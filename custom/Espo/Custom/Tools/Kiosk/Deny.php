<?php

namespace Espo\Custom\Tools\Kiosk;

/**
 * A business refusal on a kiosk action: carries the HTTP status, a stable machine code
 * (the tablet translates it into the worker's language) and optional parameters.
 * Refusals are audited and, for mutating actions, replayed identically on a repeat.
 */
class Deny extends \RuntimeException
{
    /** @param array<string, mixed> $params */
    public function __construct(
        private string $errorCode,
        private int $status = 403,
        private array $params = []
    ) {
        parent::__construct($errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->params;
    }

    public function outcome(): string
    {
        return match (true) {
            $this->status === 429 => 'RateLimited',
            $this->status === 409 || $this->status === 423 => 'Conflict',
            default => 'Denied',
        };
    }
}
