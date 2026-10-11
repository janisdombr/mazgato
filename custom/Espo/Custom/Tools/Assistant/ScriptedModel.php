<?php

namespace Espo\Custom\Tools\Assistant;

/**
 * A model that plays a fixed script and never touches the network — the test double used by
 * tests/run.sh (provider "scripted", available only while `assistantTestMode` is on).
 *
 * A script is a list of steps, one per call of respond():
 *   {"text": "...", "tools": [{"name": "statistics", "input": {...}}], "stop": "end_turn",
 *    "tokens": [in, out], "sleepMs": 0, "error": "overloaded" | "provider"}
 * Past the last step the last one repeats (a model that keeps asking for tools).
 * Every request it receives is handed to the `$recorder`, so a test can inspect exactly what a
 * real model would have been sent (system prompt, tool list, tool results).
 */
class ScriptedModel implements Model
{
    private int $i = 0;

    /**
     * @param array<int, array<string, mixed>> $script
     * @param null|callable(array<string, mixed>): void $recorder
     */
    public function __construct(private array $script, private $recorder = null, private string $id = 'scripted')
    {}

    public function name(): string
    {
        return $this->id;
    }

    public function respond(array $system, array $messages, array $tools, float $timeout): Reply
    {
        if ($this->recorder) {
            ($this->recorder)(['system' => $system, 'messages' => $messages, 'tools' => array_column($tools, 'name')]);
        }

        if (!$this->script) {
            return new Reply([['type' => 'text', 'text' => 'ok']], 'end_turn', 10, 2, 0, 0, $this->id);
        }

        $step = (array) $this->script[min($this->i, count($this->script) - 1)];
        $this->i++;

        if (!empty($step['sleepMs'])) {
            usleep(min(5000, (int) $step['sleepMs']) * 1000);
        }

        $err = $step['error'] ?? null;

        if ($err === 'overloaded') {
            throw new ModelOverloaded('The provider\'s model is overloaded right now.', 'overloaded', 529);
        }

        if ($err) {
            throw new ModelError('The provider rejected the request.', 'provider_error', 400);
        }

        $content = [];

        if (!empty($step['text'])) {
            $content[] = ['type' => 'text', 'text' => (string) $step['text']];
        }

        foreach ((array) ($step['tools'] ?? []) as $n => $t) {
            $t = (array) $t;
            $content[] = [
                'type' => 'tool_use',
                'id' => 'toolu_' . $this->i . '_' . $n,
                'name' => (string) ($t['name'] ?? ''),
                'input' => (object) (array) ($t['input'] ?? []),
            ];
        }

        $stop = (string) ($step['stop'] ?? (empty($step['tools']) ? 'end_turn' : 'tool_use'));
        $tok = (array) ($step['tokens'] ?? [100, 20]);

        return new Reply($content, $stop, (int) ($tok[0] ?? 100), (int) ($tok[1] ?? 20), 0, 0, $this->id);
    }
}
