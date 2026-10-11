<?php

namespace Espo\Custom\Tools\Assistant;

/**
 * Cost estimate per call, USD per million tokens. The figures are the ones in
 * docs/research/2026-10-08-ai-tablet-anpr-architecture.md section 2.6; check them against the
 * provider's current price list before relying on the estimate (docs/ASSISTANT.md). An unknown
 * model is priced like the dearest known one, so the estimate errs high, not low.
 */
final class Pricing
{
    /** model prefix => [input, output, cache read, cache write (5 min)] */
    private const TABLE = [
        'claude-sonnet-5-5' => [2.00, 10.00, 0.20, 2.50],
        'claude-haiku-5-5' => [0.10, 0.50, 0.01, 0.125],
    ];

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    public static function rates(string $model): array
    {
        foreach (self::TABLE as $prefix => $r) {
            if (str_starts_with($model, $prefix)) {
                return $r;
            }
        }

        return self::TABLE['claude-sonnet-5-5'];
    }

    public static function costUsd(string $model, int $in, int $out, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        [$ri, $ro, $rcr, $rcw] = self::rates($model);

        return round(($in * $ri + $out * $ro + $cacheRead * $rcr + $cacheWrite * $rcw) / 1000000, 6);
    }
}
