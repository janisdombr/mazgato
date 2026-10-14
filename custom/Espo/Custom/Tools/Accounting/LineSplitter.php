<?php

namespace Espo\Custom\Tools\Accounting;

/**
 * Splits an order's net and VAT over its priced lines in proportion to each line's gross share. Every line is rounded
 * to cents and the LAST line takes the remainder, so the lines always add up to the order to the cent (the same rule as
 * the sales journal). Pure function - unit-tested without a database.
 */
final class LineSplitter
{
    /**
     * @param list<float> $gross gross price of each line
     * @return list<array{net: float, vat: float, gross: float}>
     */
    public static function split(array $gross, float $net, float $vat): array
    {
        $n = count($gross);
        $sum = array_sum($gross);
        $restNet = round($net, 2);
        $restVat = round($vat, 2);
        $out = [];

        foreach ($gross as $i => $g) {
            $g = round($g, 2);

            if ($i === $n - 1) {
                $ln = $restNet;
                $lv = $restVat;
            } else {
                $share = $sum > 0 ? $g / $sum : 0.0;
                $ln = round($net * $share, 2);
                $lv = round($vat * $share, 2);
                $restNet = round($restNet - $ln, 2);
                $restVat = round($restVat - $lv, 2);
            }

            $out[] = ['net' => $ln, 'vat' => $lv, 'gross' => $g];
        }

        return $out;
    }
}
