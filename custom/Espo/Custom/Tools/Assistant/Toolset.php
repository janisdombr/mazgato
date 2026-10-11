<?php

namespace Espo\Custom\Tools\Assistant;

/** A group of read-only tools. Each tool answers with an array; {error:{status,detail}} is a normal answer. */
abstract class Toolset
{
    public function __construct(protected ToolContext $ctx)
    {}

    /** @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}> */
    abstract public function specs(): array;

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    abstract public function run(string $name, array $args): array;

    /** @return array<string, mixed> */
    protected function denied(string $what): array
    {
        return ['error' => ['status' => 403, 'detail' => 'Your role cannot see ' . $what . '. Tell the user; do not try to get around it.']];
    }

    /** @return array<string, mixed> */
    protected function fail(int $status, string $detail): array
    {
        return ['error' => ['status' => $status, 'detail' => $detail]];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{0: string, 1: string}|null  local "Y-m-d" from/to (inclusive), null when invalid
     */
    protected function period(array $args, string $defaultFrom = 'first day of this month', ?string $defaultTo = null): ?array
    {
        $tz = new \DateTimeZone($this->ctx->cfg->timeZone());
        $now = new \DateTimeImmutable('now', $tz);
        $parse = function ($v, string $default) use ($tz, $now): ?\DateTimeImmutable {
            $v = trim((string) $v);

            if ($v === '') {
                return $now->modify($default)->setTime(0, 0);
            }

            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($v, 0, 10), $tz);

            return $d && $d->format('Y-m-d') === substr($v, 0, 10) ? $d : null;
        };

        $from = $parse($args['dateFrom'] ?? '', $defaultFrom);
        $to = $parse($args['dateTo'] ?? '', $defaultTo ?? 'today');

        if (!$from || !$to || $to < $from || $from->diff($to)->days > 400) {
            return null;
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /** @return array<string, mixed> */
    protected function periodSchema(string $note = ''): array
    {
        return [
            'dateFrom' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD (local). Default: first day of this month.' . $note],
            'dateTo' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD (local), inclusive. Default: today.'],
        ];
    }

    protected function clamp(mixed $v, int $min, int $max, int $default): int
    {
        return is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
    }
}
