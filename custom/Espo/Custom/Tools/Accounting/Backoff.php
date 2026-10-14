<?php

namespace Espo\Custom\Tools\Accounting;

/** Exponential pause between attempts: 1, 2, 4 ... minutes, capped at 6 hours; gives up after MAX attempts. */
final class Backoff
{
    public const MAX_ATTEMPTS = 8;

    /** Seconds to wait after the Nth failed attempt (N >= 1), or null when no more automatic tries are due. */
    public static function delay(int $attempts): ?int
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        return (int) min(6 * 3600, 60 * (2 ** max(0, $attempts - 1)));
    }
}
