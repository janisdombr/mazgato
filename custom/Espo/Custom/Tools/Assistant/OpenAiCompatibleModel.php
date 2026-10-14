<?php

namespace Espo\Custom\Tools\Assistant;

use Espo\Core\Utils\Log;

/**
 * The chat.completions API over plain HTTPS (curl, no SDK, no composer dependency). Speaks to OpenAI and to every
 * provider that offers the same wire format (Gemini's compatibility endpoint, Mistral, DeepSeek, Groq ...). The
 * loop keeps its Messages-API shaped history; this class translates it on the way out and back:
 *
 *   system blocks            -> one {role: system} message
 *   assistant text/tool_use  -> {role: assistant, content, tool_calls[{id, type: function, function{name, arguments}}]}
 *   user tool_result blocks  -> one {role: tool, tool_call_id, content} each (is_error is carried in the text)
 *   thinking blocks          -> dropped (they belong to another API)
 *
 * - one request per step, no retry (the FallbackModel makes the one deliberate second attempt);
 * - 429 / 5xx -> ModelOverloaded; 401/403 -> "key refused"; other 4xx -> ModelError with the API's message, scrubbed;
 * - the key is a Secret: sent in the Authorization header only, scrubbed from every message and log line;
 * - the base URL comes from the catalogue (Providers), never from the database (AssistantConfig::apiBase());
 * - token usage: `prompt_tokens` INCLUDES cached tokens, so the Reply carries the uncached part as input and the cached
 *   part as cache read (the shape Pricing expects). Cache writes are not billed separately by these providers.
 */
class OpenAiCompatibleModel implements Model
{
    public function __construct(
        private AssistantConfig $config,
        private string $modelId,
        private Log $log,
        private string $provider = 'openai'
    ) {}

    public function name(): string
    {
        return $this->modelId;
    }

    public function __toString(): string
    {
        return 'OpenAiCompatibleModel(' . $this->provider . ':' . $this->modelId . ')';
    }

    public function respond(array $system, array $messages, array $tools, float $timeout): Reply
    {
        $key = $this->config->key();

        if (!$key->isSet()) {
            throw new ModelError('The API key is not set.', 'not_configured');
        }

        $spec = Providers::get($this->provider);
        $body = [
            'model' => $this->modelId,
            $spec['tokenParam'] => $this->config->maxOutputTokens(),
            'messages' => self::translate($system, $messages),
        ];

        if ($tools) {
            $body['tools'] = array_map(fn($t) => [
                'type' => 'function',
                'function' => [
                    'name' => $t['name'],
                    'description' => $t['description'],
                    'parameters' => self::schema($t['input_schema'] ?? []),
                ],
            ], $tools);
            $body['tool_choice'] = 'auto';
        }

        if ($this->provider === 'openai') {
            $body['store'] = false; // do not keep the conversation in the provider's stored completions

            if (preg_match('/^gpt-5(-|$)/', $this->modelId)) {
                // the gpt-5 family reasons by default and the reasoning shares the answer's token budget
                $body['reasoning_effort'] = 'minimal';
            }
        }

        if ($this->provider === 'gemini' && preg_match('/^gemini-3/', $this->modelId)) {
            // Gemini 3 thinks by default and the thinking shares the answer's token budget (an answer cut after two words)
            // the lite models accept "minimal"; the bigger ones refuse it (400 "Thinking level MINIMAL is not supported"), they take "low"
            $body['reasoning_effort'] = str_contains($this->modelId, 'lite') ? 'minimal' : 'low';
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $ch = curl_init(rtrim($this->config->apiBase(), '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(2, (int) ceil($timeout)),
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'authorization: Bearer ' . $key->reveal(),
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($raw === false || $errno) {
            $this->log->warning('Assistant: provider unreachable (curl ' . $errno . ')');

            throw new ModelOverloaded($errno === CURLE_OPERATION_TIMEDOUT
                ? 'The provider did not answer in time.'
                : 'The provider could not be reached.', 'provider_unreachable');
        }

        $data = json_decode((string) $raw, true);
        $err = is_array($data) ? (is_array($data['error'] ?? null) ? $data['error'] : (is_array($data[0]['error'] ?? null) ? $data[0]['error'] : [])) : [];
        $type = (string) ($err['type'] ?? $err['status'] ?? $err['code'] ?? '');
        $type = preg_replace('/[^A-Za-z0-9_.\-]/', '', mb_substr($type, 0, 40));

        if ($status === 401 || $status === 403) {
            throw new ModelError('The provider refused the configured key.', 'key_refused', $status);
        }

        if ($status === 408 || $status === 429 || $status >= 500) {
            $this->log->warning('Assistant: provider answered HTTP ' . $status . ' ' . $type);

            throw new ModelOverloaded($status === 429
                ? 'The provider is rate-limiting this installation; try again shortly.'
                : 'The provider\'s model is overloaded right now.', $status === 429 ? 'rate_limited' : 'overloaded', $status);
        }

        if ($status !== 200 || !is_array($data)) {
            $why = trim((string) preg_replace('/[^\x20-\x7E]/', ' ', mb_substr((string) ($err['message'] ?? ''), 0, 200)));
            $this->log->warning('Assistant: provider answered HTTP ' . $status . ' ' . $type . ($why !== '' ? ' - ' . $why : ''));
            $msg = $key->scrub(mb_substr((string) ($err['message'] ?? ''), 0, 160));

            throw new ModelError('The provider rejected the request (HTTP ' . $status . ($type ? ', ' . $type : '') . ($msg ? ': ' . $msg : '') . ').', 'provider_error', $status);
        }

        $choice = (array) ($data['choices'][0] ?? []);
        $msg = (array) ($choice['message'] ?? []);
        $content = [];
        $text = $msg['content'] ?? '';

        if (is_array($text)) { // some providers answer with content parts
            $text = implode('', array_map(fn($p) => is_array($p) ? (string) ($p['text'] ?? '') : '', $text));
        }

        if (is_string($text) && trim($text) !== '') {
            $content[] = ['type' => 'text', 'text' => $text];
        }

        $n = 0;

        foreach ((array) ($msg['tool_calls'] ?? []) as $c) {
            $n++;
            $args = json_decode((string) ($c['function']['arguments'] ?? ''), true);
            $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($c['id'] ?? ''));
            $block = [
                'type' => 'tool_use',
                'id' => $id !== '' ? mb_substr($id, 0, 64) : 'call_' . $n . '_' . substr(md5((string) $raw), 0, 6),
                'name' => (string) ($c['function']['name'] ?? ''),
                'input' => (object) (is_array($args) ? $args : []),
            ];

            // Gemini 3 signs its tool calls (extra_content.google.thought_signature) and refuses the next request unless the
            // call is sent back WITH the signature; kept verbatim, bounded
            if (is_array($c['extra_content'] ?? null) && strlen((string) json_encode($c['extra_content'])) <= 8000) {
                $block['extra_content'] = $c['extra_content'];
            }

            $content[] = $block;
        }

        $finish = (string) ($choice['finish_reason'] ?? '');
        $stop = match (true) {
            $n > 0 => 'tool_use',
            $finish === 'length' => 'max_tokens',
            $finish === 'content_filter' => 'refusal',
            $finish === 'stop' || $finish === '' => 'end_turn',
            default => 'other',
        };

        $u = (array) ($data['usage'] ?? []);
        $prompt = (int) ($u['prompt_tokens'] ?? 0);
        $cached = (int) ($u['prompt_tokens_details']['cached_tokens'] ?? $u['prompt_cache_hit_tokens'] ?? 0);
        $cached = max(0, min($cached, $prompt));

        return new Reply(
            $content,
            $stop,
            $prompt - $cached,
            (int) ($u['completion_tokens'] ?? 0),
            $cached,
            0,
            $this->modelId
        );
    }

    /**
     * The Messages-API history as chat.completions messages.
     *
     * @param array<int, array<string, mixed>> $system
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public static function translate(array $system, array $messages): array
    {
        $out = [['role' => 'system', 'content' => implode("\n\n", array_map(fn($b) => (string) $b['text'], $system))]];

        foreach ($messages as $m) {
            $content = $m['content'] ?? '';
            $blocks = is_array($content) ? $content : [['type' => 'text', 'text' => (string) $content]];

            if (($m['role'] ?? '') === 'assistant') {
                $text = [];
                $calls = [];

                foreach ($blocks as $b) {
                    $t = $b['type'] ?? '';

                    if ($t === 'text') {
                        $text[] = (string) $b['text'];
                    } elseif ($t === 'tool_use') {
                        $call = [
                            'id' => (string) $b['id'],
                            'type' => 'function',
                            'function' => ['name' => (string) $b['name'], 'arguments' => json_encode((object) (array) ($b['input'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)],
                        ];

                        if (is_array($b['extra_content'] ?? null)) {
                            $call['extra_content'] = $b['extra_content'];
                        }

                        $calls[] = $call;
                    }
                }

                $a = ['role' => 'assistant', 'content' => $text ? implode("\n", $text) : ($calls ? null : '(…)')];

                if ($calls) {
                    $a['tool_calls'] = $calls;
                }

                $out[] = $a;

                continue;
            }

            $text = [];

            foreach ($blocks as $b) {
                $t = $b['type'] ?? '';

                if ($t === 'tool_result') {
                    $c = $b['content'] ?? '';
                    $c = is_string($c) ? $c : json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    $out[] = ['role' => 'tool', 'tool_call_id' => (string) $b['tool_use_id'], 'content' => (!empty($b['is_error']) ? 'ERROR: ' : '') . $c];
                } elseif ($t === 'text') {
                    $text[] = (string) $b['text'];
                }
            }

            if ($text) {
                $out[] = ['role' => 'user', 'content' => implode("\n", $text)];
            }
        }

        return $out;
    }

    /** An empty `properties` list must go out as `{}` (a JSON object), not `[]`. */
    private static function schema(array $s): array
    {
        if (array_key_exists('properties', $s) && !$s['properties']) {
            $s['properties'] = new \stdClass();
        }

        return $s ?: ['type' => 'object', 'properties' => new \stdClass()];
    }
}
