<?php

namespace Espo\Custom\Tools\Assistant;

/** What a model said in one step. */
final class Reply
{
    /**
     * @param array<int, array<string, mixed>> $content  text / tool_use blocks, as the next request sends them back
     * @param string $stop  end_turn | tool_use | max_tokens | refusal | other
     */
    public function __construct(
        public array $content,
        public string $stop = 'end_turn',
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        public string $model = ''
    ) {}

    public function text(): string
    {
        $out = [];

        foreach ($this->content as $b) {
            if (($b['type'] ?? '') === 'text') {
                $out[] = (string) $b['text'];
            }
        }

        return trim(implode("\n", $out));
    }

    /** @return array<int, array{id: string, name: string, input: array<string, mixed>}> */
    public function toolCalls(): array
    {
        $out = [];

        foreach ($this->content as $b) {
            if (($b['type'] ?? '') === 'tool_use') {
                $out[] = ['id' => (string) $b['id'], 'name' => (string) $b['name'], 'input' => (array) ($b['input'] ?? [])];
            }
        }

        return $out;
    }
}
