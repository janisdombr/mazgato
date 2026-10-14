<?php

namespace Espo\Custom\Tools\Accounting;

/**
 * A connector call failed. `transient` = worth retrying later (network, timeout, 5xx, rate limit); otherwise the
 * request itself is wrong (4xx, validation, missing mapping) and only a person fixing the setup helps.
 * `rateLimited` stops the whole run so the remaining rows do not hammer the provider.
 * The message must already be free of secrets (Cfg::scrub) - it is stored on the ledger row and shown in the admin page.
 */
class ConnectorException extends \RuntimeException
{
    public function __construct(string $message, private bool $transient = false, private bool $rateLimited = false)
    {
        parent::__construct($message);
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }

    public function isRateLimited(): bool
    {
        return $this->rateLimited;
    }
}
