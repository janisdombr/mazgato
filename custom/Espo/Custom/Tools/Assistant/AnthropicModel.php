<?php

namespace Espo\Custom\Tools\Assistant;

use Espo\Core\Utils\Log;

/**
 * The Messages API over plain HTTPS (curl, no SDK, no composer dependency).
 *
 *   POST {base}/v1/messages   x-api-key, anthropic-version, content-type
 *
 * - system is an array of blocks; a block flagged `cache` gets cache_control {type: ephemeral},
 *   so tools + the rules prefix is read from the prompt cache on the following steps/questions.
 *   The tool list is sent in a fixed order for the same reason.
 * - one request per step, no automatic retry: a retry would restart the wait inside the same
 *   deadline; the FallbackModel makes the one deliberate second attempt.
 * - 429 / 5xx / 529 -> ModelOverloaded; 401/403 -> "key refused"; 400 -> the API's error type.
 * - the key is a Secret: it is sent in the header only and scrubbed from every message and log line.
 */
class AnthropicModel implements Model
{
    private const VERSION = '2023-06-01';

    public function __construct(
        private AssistantConfig $config,
        private string $modelId,
        private Log $log,
        private bool $withEffort = true
    ) {}

    public function name(): string
    {
        return $this->modelId;
    }

    public function __toString(): string
    {
        return 'AnthropicModel(' . $this->modelId . ')';
    }

    public function respond(array $system, array $messages, array $tools, float $timeout): Reply
    {
        $key = $this->config->key();

        if (!$key->isSet()) {
            throw new ModelError('The API key is not set.', 'not_configured');
        }

        $sys = [];

        foreach ($system as $b) {
            $block = ['type' => 'text', 'text' => (string) $b['text']];

            if (!empty($b['cache'])) {
                $block['cache_control'] = ['type' => 'ephemeral'];
            }

            $sys[] = $block;
        }

        $body = [
            'model' => $this->modelId,
            'max_tokens' => $this->config->maxOutputTokens(),
            'system' => $sys,
            'messages' => $messages,
        ];

        if ($tools) {
            $body['tools'] = array_map(fn($t) => [
                'name' => $t['name'],
                'description' => $t['description'],
                'input_schema' => $t['input_schema'],
            ], $tools);
        }

        if ($this->withEffort && $this->config->effort() !== '' && str_starts_with($this->modelId, 'claude-sonnet-5')) {
            $body['output_config'] = ['effort' => $this->config->effort()];
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $ch = curl_init($this->config->apiBase() . '/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(2, (int) ceil($timeout)),
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'anthropic-version: ' . self::VERSION,
                'x-api-key: ' . $key->reveal(),
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
        $type = is_array($data) ? (string) ($data['error']['type'] ?? '') : '';

        if ($status === 401 || $status === 403) {
            throw new ModelError('The provider refused the configured key.', 'key_refused', $status);
        }

        if ($status === 429 || $status >= 500) {
            $this->log->warning('Assistant: provider answered HTTP ' . $status . ' ' . $type);

            throw new ModelOverloaded($status === 429
                ? 'The provider is rate-limiting this installation; try again shortly.'
                : 'The provider\'s model is overloaded right now.', $status === 429 ? 'rate_limited' : 'overloaded', $status);
        }

        if ($status !== 200 || !is_array($data)) {
            $this->log->warning('Assistant: provider answered HTTP ' . $status . ' ' . $type);
            $msg = is_array($data) ? $key->scrub(mb_substr((string) ($data['error']['message'] ?? ''), 0, 160)) : '';

            throw new ModelError('The provider rejected the request (HTTP ' . $status . ($type ? ', ' . $type : '') . ($msg ? ': ' . $msg : '') . ').', 'provider_error', $status);
        }

        $content = [];

        foreach ((array) ($data['content'] ?? []) as $b) {
            $t = $b['type'] ?? '';

            if ($t === 'thinking' || $t === 'redacted_thinking') {
                // Thinking is adaptive on the Claude 5 models. Within a tool loop the blocks must go back to
                // the model unchanged (text, signature and all), or the next step can be refused with a 400.
                $content[] = $b;
            } elseif ($t === 'text') {
                $content[] = ['type' => 'text', 'text' => (string) ($b['text'] ?? '')];
            } elseif ($t === 'tool_use') {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => (string) $b['id'],
                    'name' => (string) $b['name'],
                    'input' => (object) (is_array($b['input'] ?? null) ? $b['input'] : []),
                ];
            }
        }

        $u = (array) ($data['usage'] ?? []);
        $stop = (string) ($data['stop_reason'] ?? 'other');

        return new Reply(
            $content,
            in_array($stop, ['end_turn', 'tool_use', 'max_tokens', 'refusal'], true) ? $stop : 'other',
            (int) ($u['input_tokens'] ?? 0),
            (int) ($u['output_tokens'] ?? 0),
            (int) ($u['cache_read_input_tokens'] ?? 0),
            (int) ($u['cache_creation_input_tokens'] ?? 0),
            $this->modelId
        );
    }
}
