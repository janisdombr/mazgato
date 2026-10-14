<?php

namespace Espo\Custom\Tools\ClientAssistant;

/**
 * Phone numbers and e-mail addresses a visitor types never reach the model or the journal's
 * question text: they are replaced by placeholders ([PHONE-1], [EMAIL-1]) and the real value is
 * kept in this per-chat vault (stored in the journal row, blanked after a day by the retention job).
 *
 * The model can still BOOK: `create_booking` is given the placeholder and the server swaps in the
 * real value just before it calls the booking code. The model therefore never sees, repeats or
 * leaks a contact detail; a placeholder it invents resolves to nothing.
 */
final class ContactVault
{
    /** @param array<string, string> $map placeholder => real value */
    public function __construct(private array $map = [])
    {}

    /** @return array<string, string> */
    public function map(): array
    {
        return $this->map;
    }

    /** The visitor's own numbers back into the text they are about to read (never into the journal or a model request). */
    public function restore(string $text): string
    {
        return $this->map ? strtr($text, $this->map) : $text;
    }

    private function placeholderFor(string $kind, string $value): string
    {
        $found = array_search($value, $this->map, true);

        if ($found !== false && str_starts_with((string) $found, '[' . $kind . '-')) {
            return (string) $found;
        }

        $n = 1;

        foreach (array_keys($this->map) as $k) {
            if (str_starts_with($k, '[' . $kind . '-')) {
                $n++;
            }
        }

        $ph = '[' . $kind . '-' . $n . ']';
        $this->map[$ph] = $value;

        return $ph;
    }

    /** Replace e-mails and phone numbers; also neutralise anything that already looks like a placeholder. */
    public function protect(string $text): string
    {
        // a visitor cannot forge a placeholder to address somebody else's vault entry
        $text = (string) preg_replace('/\[(PHONE|EMAIL)-(\d+)\]/i', '(\1 \2)', $text);

        $text = (string) preg_replace_callback('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u', fn($m) => $this->placeholderFor('EMAIL', mb_strtolower($m[0])), $text);

        return (string) preg_replace_callback('/(?<![\w])\+?\d[\d\s().\-]{6,}\d(?![\w])/', function ($m) {
            $digits = preg_replace('/\D+/', '', $m[0]) ?? '';

            if (strlen($digits) < 8 || strlen($digits) > 15 || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($m[0]))) {
                return $m[0];
            }

            return $this->placeholderFor('PHONE', trim($m[0]));
        }, $text);
    }

    /**
     * What a tool argument stands for: a placeholder -> its real value; a literal value only when the
     * visitor typed exactly these digits / this address in the CURRENT message (so a model cannot
     * conjure a number); anything else null.
     */
    public function resolve(string $arg, string $rawCurrent, string $kind): ?string
    {
        $arg = trim($arg);

        if (isset($this->map[$arg])) {
            return str_starts_with($arg, '[' . $kind . '-') ? $this->map[$arg] : null;
        }

        if ($kind === 'PHONE') {
            $d = preg_replace('/\D+/', '', $arg) ?? '';

            return strlen($d) >= 8 && str_contains(preg_replace('/\D+/', '', $rawCurrent) ?? '', $d) ? $arg : null;
        }

        return $arg !== '' && stripos($rawCurrent, $arg) !== false ? $arg : null;
    }
}
