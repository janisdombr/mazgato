<?php

namespace Espo\Custom\Tools\Assistant;

/** Masks of direct identifiers: a phone keeps its country prefix and last two digits, an e-mail its first letter and domain. */
final class Mask
{
    public static function phone(?string $p): string
    {
        $digits = preg_replace('/\D+/', '', (string) $p) ?? '';

        if (strlen($digits) < 6) {
            return '***';
        }

        return '+' . substr($digits, 0, 3) . ' **** **' . substr($digits, -2);
    }

    public static function email(?string $e): string
    {
        $e = (string) $e;
        $at = strrpos($e, '@');

        if ($at === false || $at < 1) {
            return '***';
        }

        return mb_substr($e, 0, 1) . '***' . substr($e, $at);
    }

    /** Phones and e-mail addresses typed into a question never reach the model unmasked. */
    public static function inText(string $text): string
    {
        $text = (string) preg_replace_callback('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', fn($m) => self::email($m[0]), $text);

        return (string) preg_replace_callback('/(?<![\w])\+?\d[\d\s().\-]{6,}\d(?![\w])/', function ($m) {
            // dates (2026-10-01) and plain numbers with few digits are not phones
            $digits = preg_replace('/\D+/', '', $m[0]) ?? '';

            if (strlen($digits) < 8 || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($m[0]))) {
                return $m[0];
            }

            return self::phone($m[0]);
        }, $text);
    }
}
