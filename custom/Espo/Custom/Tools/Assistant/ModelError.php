<?php

namespace Espo\Custom\Tools\Assistant;

/** The provider could not answer. The message is safe to show: it never carries the key. */
class ModelError extends \RuntimeException
{
    public function __construct(string $message, public string $code2 = 'provider_error', public int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
