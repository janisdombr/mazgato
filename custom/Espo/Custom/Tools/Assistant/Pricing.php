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
        // Other providers (public customer chat), read 2026-10-12 from the providers' price pages. No separate
        // cache-write charge there (write = input rate). Order matters: the first matching prefix wins.
        'gpt-5.4-nano' => [0.20, 1.25, 0.02, 0.20],
        'gpt-5.4-mini' => [0.75, 4.50, 0.075, 0.75],
        'gpt-5-nano' => [0.05, 0.40, 0.005, 0.05],
        'gpt-5-mini' => [0.25, 2.00, 0.025, 0.25],
        'gpt-4.1-nano' => [0.10, 0.40, 0.025, 0.10],
        'gpt-4.1-mini' => [0.40, 1.60, 0.10, 0.40],
        'gpt-4o-mini' => [0.15, 0.60, 0.075, 0.15],
        'gemini-2.5-flash-lite' => [0.10, 0.40, 0.01, 0.10],
        'gemini-3.1-flash-lite' => [0.25, 1.50, 0.025, 0.25],
        'gemini-3.5-flash-lite' => [0.30, 2.50, 0.03, 0.30],
        'gemini-2.5-flash' => [0.30, 2.50, 0.03, 0.30],
        // introductory 0.75 / 3.75 until 2026-12-31; the 2027 list price is used so the estimate errs high
        'gemini-3.8-flash' => [1.50, 7.50, 0.15, 1.50],
        'gemini-3.6-flash' => [1.50, 7.50, 0.15, 1.50],
        // DeepSeek has peak/off-peak prices; the peak ones are used
        'deepseek-flash' => [0.30, 1.20, 0.006, 0.30],
        // NOT confirmed on Mistral's own page (aggregators disagree: 0.10-0.20 in / 0.30-0.60 out); the high end
        'mistral-small' => [0.20, 0.60, 0.02, 0.20],
        // not listed on purpose: ministral-*, Groq models -> priced like the dearest known model
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
