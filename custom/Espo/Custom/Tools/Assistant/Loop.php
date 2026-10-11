<?php

namespace Espo\Custom\Tools\Assistant;

/**
 * One question, answered: a bounded loop  model -> tool -> result -> model.
 *
 * The bounds are not suggestions: at most `maxSteps` model calls (the config clamps it to 8), a
 * wall-clock deadline, and a ceiling on the input tokens one question may read. Each tool runs
 * through `$runTool`, which the caller binds to the asking user (ACL) — the model chooses WHICH
 * tool and WITH WHAT arguments, nothing else. Tool results are capped in size.
 */
class Loop
{
    public const MAX_TOOL_RESULT_CHARS = 24000;

    public const STEP_CAP = 'I could not finish this within the limit of %d steps, so I stopped rather than guess. Try a narrower question.';
    public const CUT_OFF = 'The answer was cut off by the length limit before it said anything usable. Try a narrower question.';
    public const REFUSED = 'The model declined to answer this question.';
    public const DEADLINE = 'I ran out of time for this question (%d seconds) and stopped rather than guess.';
    public const BUDGET = 'This question needed more reading than one question is allowed (%d input tokens), so I stopped. Try a narrower question.';

    /**
     * @param array<int, array<string, mixed>> $system
     * @param array<int, array<string, mixed>> $history  earlier messages (text only)
     * @param array<int, array<string, mixed>> $toolSpecs
     * @param callable(string, array<string, mixed>): array<string, mixed> $runTool
     * @param null|callable(): float $clock
     */
    public function run(
        Model $model,
        array $system,
        array $history,
        string $question,
        array $toolSpecs,
        callable $runTool,
        AssistantConfig $config,
        ?callable $clock = null
    ): TurnResult {
        $clock = $clock ?? fn() => microtime(true);
        $res = new TurnResult();
        $messages = array_merge($history, [['role' => 'user', 'content' => [['type' => 'text', 'text' => $question]]]]);
        $sources = [$question];
        $maxSteps = $config->maxSteps();
        $seconds = $config->turnSeconds();
        $deadline = $clock() + $seconds;
        $known = array_column($toolSpecs, 'name');

        $finish = function (string $stopped, string $text, string $code = '') use ($res, &$sources): TurnResult {
            $res->stopped = $stopped;
            $res->text = $text;
            $res->errorCode = $code;

            if ($stopped === 'answered') {
                $res->unsourced = Unsourced::find($text, $sources);
            }

            return $res;
        };

        for ($step = 0; $step < $maxSteps; $step++) {
            $left = $deadline - $clock();

            if ($left <= 0) {
                return $finish('deadline', sprintf(self::DEADLINE, $seconds), 'deadline');
            }

            try {
                $reply = $model->respond($system, $messages, $toolSpecs, $left);
            } catch (ModelError $e) {
                return $finish('provider_error', 'The assistant could not answer: ' . $e->getMessage(), $e->code2);
            }

            $res->steps++;
            $res->tokensIn += $reply->inputTokens;
            $res->tokensOut += $reply->outputTokens;
            $res->cacheRead += $reply->cacheReadTokens;
            $res->cacheWrite += $reply->cacheWriteTokens;
            $used = $reply->model ?: $model->name();
            $res->costUsd += Pricing::costUsd($used, $reply->inputTokens, $reply->outputTokens, $reply->cacheReadTokens, $reply->cacheWriteTokens);

            if (!in_array($used, $res->models, true)) {
                $res->models[] = $used;
            }

            if ($reply->stop === 'refusal') {
                return $finish('refused', self::REFUSED, 'refused');
            }

            $calls = $reply->toolCalls();

            if (!$calls) {
                $text = $reply->text();

                return $text !== '' ? $finish('answered', $text) : $finish('cut_off', self::CUT_OFF, 'cut_off');
            }

            if ($res->tokensIn + $res->cacheRead + $res->cacheWrite > $config->turnInputTokens()) {
                return $finish('budget', sprintf(self::BUDGET, $config->turnInputTokens()), 'budget');
            }

            $messages[] = ['role' => 'assistant', 'content' => $reply->content];
            $blocks = [];

            foreach ($calls as $c) {
                // A name the model made up is not a tool: it gets an error, nothing runs.
                $outcome = in_array($c['name'], $known, true)
                    ? $runTool($c['name'], $c['input'])
                    : ['error' => ['status' => 404, 'detail' => 'unknown tool']];

                // `_client` is for the operator's screen only (a draft's full attributes, with real names):
                // it is taken off before anything is encoded for the model.
                $client = is_array($outcome['_client'] ?? null) ? $outcome['_client'] : [];
                unset($outcome['_client']);

                if (is_array($client['draft'] ?? null) && !empty($client['draft']['entityType'])) {
                    $res->drafts[] = $client['draft'];
                }

                $full = json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

                $sent = $full;

                if (strlen($full) > self::MAX_TOOL_RESULT_CHARS) {
                    $sent = json_encode(['error' => [
                        'status' => 413,
                        'detail' => 'the result is ' . strlen($full) . ' characters, over the ' . self::MAX_TOOL_RESULT_CHARS . ' sent to the assistant; ask for less (a shorter period, a smaller limit)',
                    ]]);
                }

                $sources[] = $sent;
                $res->tools[] = [
                    'name' => $c['name'],
                    'input' => $c['input'],
                    'chars' => strlen($full),
                    'error' => isset($outcome['error']) ? (int) ($outcome['error']['status'] ?? 0) : null,
                    'source' => $outcome['source'] ?? null,
                    'period' => $outcome['period'] ?? null,
                ];
                $blocks[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $c['id'],
                    'content' => $sent,
                    'is_error' => isset($outcome['error']),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $blocks];
        }

        return $finish('step_cap', sprintf(self::STEP_CAP, $maxSteps), 'step_cap');
    }
}
