<?php

namespace Espo\Custom\Tools\Assistant;

/**
 * Figures in an answer that appear in no tool result of this question (and not in the question
 * itself). Not a block — a year in a sentence is a number too — but the panel lists them next to
 * the answer, so a figure the model made up is visible as such.
 */
final class Unsourced
{
    /** @return string[] */
    private static function numbers(string $text): array
    {
        preg_match_all('/\d{1,3}(?:[\x{00A0} ,]\d{3})+(?:[.,]\d{1,2})?(?!\d)|\d+(?:[.,]\d+)*/u', $text, $m);
        $out = [];

        foreach ($m[0] as $raw) {
            $parts = [];

            if (preg_match('/^\d{1,3}(?:[\x{00A0} ,]\d{3})+(?:[.,]\d{1,2})?$/u', $raw)) {
                $n = preg_replace('/[\x{00A0} ,](?=\d{3})/u', '', $raw);
                $parts[] = str_replace(',', '.', (string) $n);
            } elseif (preg_match('/^\d+([.,]\d+){2,}$/', $raw)) {
                // 10.10.2026 / 1,2,3: a date or a list, not one figure
                $parts = preg_split('/[.,]/', $raw) ?: [];
            } elseif (preg_match('/^\d+,\d{3}$/', $raw)) {
                $parts[] = str_replace(',', '', $raw);
            } else {
                $parts[] = str_replace(',', '.', $raw);
            }

            foreach ($parts as $n) {
                if (strlen(str_replace('.', '', $n)) >= 2) {
                    $out[$n] = true;
                }
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * @param string[] $sources
     * @return string[]
     */
    public static function find(string $answer, array $sources): array
    {
        $seen = [];

        foreach ($sources as $s) {
            foreach (self::numbers($s) as $n) {
                $seen[$n] = true;
                $seen[preg_replace('/\.0+$|(\.\d)0$/', '$1', $n)] = true;
            }
        }

        $out = [];

        foreach (self::numbers($answer) as $n) {
            $short = preg_replace('/\.0+$|(\.\d)0$/', '$1', $n);

            if (!isset($seen[$n]) && !isset($seen[$short])) {
                $out[] = $n;
            }
        }

        sort($out);

        return $out;
    }
}
