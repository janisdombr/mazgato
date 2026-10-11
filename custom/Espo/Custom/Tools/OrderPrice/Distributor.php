<?php

namespace Espo\Custom\Tools\OrderPrice;

/**
 * Brings the order's lines to an explicitly typed total, in whole cents.
 *
 * - Total ABOVE (or equal to) the sum of the lines: surcharge - the difference goes onto the base line,
 *   the add-ons stay as charged.
 * - Total BELOW the sum: a discount (any size, down to 0 = 100 %). Every line is scaled in proportion, each
 *   rounded down to the cent; the cents that are left over go onto the base line (or the first line when
 *   the order has no base), so the lines add up to the typed total exactly.
 *
 * Pure arithmetic: no database, one definition for createOrder and for an edit of the total on the order.
 */
final class Distributor
{
    /**
     * @param array<string,float> $prices  line key => current price
     * @param string|null         $baseKey key of the base line, if any
     * @return array<string,float>         line key => new price (same keys, same order)
     */
    public static function fit(array $prices, ?string $baseKey, float $target): array
    {
        if ($prices === []) {
            return [];
        }

        $target = max(0.0, $target);
        $T = (int) round($target * 100);

        $cents = [];
        $S = 0;

        foreach ($prices as $k => $p) {
            $cents[$k] = (int) round(((float) $p) * 100);
            $S += $cents[$k];
        }

        $anchor = ($baseKey !== null && array_key_exists($baseKey, $cents)) ? $baseKey : array_key_first($cents);

        if ($T === $S) {
            return array_map(fn (int $c): float => $c / 100, $cents);
        }

        $out = [];

        if ($T > $S || $S === 0) {
            $out = $cents;
            $out[$anchor] += $T - $S;
        } else {
            $others = 0;

            foreach ($cents as $k => $c) {
                if ($k === $anchor) {
                    continue;
                }

                $out[$k] = intdiv($c * $T, $S);
                $others += $out[$k];
            }

            $out[$anchor] = $T - $others;
        }

        $result = [];

        foreach ($cents as $k => $_) {
            $result[$k] = $out[$k] / 100;
        }

        return $result;
    }
}
