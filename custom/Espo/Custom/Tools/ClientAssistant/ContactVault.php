<?php

namespace Espo\Custom\Tools\ClientAssistant;

/**
 * Phone numbers and e-mail addresses a visitor types never reach the model or the journal's
 * question text: they are replaced by placeholders ([PHONE-1], [EMAIL-1]) and the real value is
 * kept in this per-chat vault (stored in the journal row, blanked after a day by the retention job).
 *
 * The same vault now also holds the visitor's NAME and PLATE ([NAME-1], [PLATE-1]), normally given through
 * the widget's details form (never typed into the chat, so never sent to the AI provider). A plate that is
 * typed in free text is masked when it clearly looks like a Baltic registration (see PLATE_RE below); a name
 * in free text cannot be detected, only a name already in the vault is masked when it is typed again.
 *
 * The model can still BOOK: `create_booking` is given the placeholders and the server swaps in the
 * real values just before it calls the booking code. The model therefore never sees, repeats or
 * leaks a contact detail; a placeholder it invents resolves to nothing.
 */
final class ContactVault
{
    public const KINDS = ['PHONE', 'EMAIL', 'NAME', 'PLATE'];

    /**
     * A registration number typed in free text. Deliberately narrow (a missed plate is the visitor's own free text; a false
     * positive would hide a price or a service name from the model):
     *   LV  2 letters + 3-4 digits   AB1234  AB-1234  AB 1234   (3 digits: capitals only; a space: capitals only and not a year)
     *   LT  3 letters + 3 digits     ABC123  ABC-123  ABC 123
     *   EE  3 digits + 3 letters     123ABC  123-ABC  123 ABC
     * A space between the groups needs CAPITALS; unit and make words (EUR, MIN, BMW, XL ...) are never plates. Whole tokens
     * only: "Mazgasana M", "Premium 2000", "25.50 EUR", "12:00", "45 min", "M2" and "2026-10-12" are left alone.
     */
    private const PLATE_RE = '/(?<![\p{L}\p{N}])(?:([A-Za-z]{2,3})([- ]?)(\d{3,4})|(\d{3})([- ]?)([A-Za-z]{3}))(?![\p{L}\p{N}])/u';

    private const NOT_PLATE = ['EUR', 'USD', 'GBP', 'SEK', 'RUB', 'MIN', 'MAX', 'KMH', 'PVN', 'BMW', 'MAN', 'DAF', 'KIA', 'VAZ', 'GAZ', 'UAZ', 'SUV', 'XXL', 'LED',
        'XL', 'XS', 'NO', 'UZ', 'AR', 'KM', 'CM', 'MM', 'ML', 'KG', 'MP', 'ID', 'PL', 'NR', 'TV', 'WC', 'VW', 'MG', 'GL', 'GT', 'LV', 'LT', 'EE'];

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

    /** Replace e-mails, phone numbers and clearly plate-shaped strings; also neutralise anything that already looks like a placeholder. */
    public function protect(string $text): string
    {
        // a visitor cannot forge a placeholder to address somebody else's vault entry
        $text = (string) preg_replace('/\[(PHONE|EMAIL|NAME|PLATE)-(\d+)\]/i', '(\1 \2)', $text);

        $text = (string) preg_replace_callback('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u', fn($m) => $this->placeholderFor('EMAIL', mb_strtolower($m[0])), $text);

        $text = (string) preg_replace_callback('/(?<![\w])(?!\d{4}-\d{2}-\d{2})\+?\d[\d\s().\-]{6,}\d(?![\w])/', function ($m) {
            $digits = preg_replace('/\D+/', '', $m[0]) ?? '';

            if (strlen($digits) < 8 || strlen($digits) > 15 || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($m[0]))) {
                return $m[0];
            }

            return $this->placeholderFor('PHONE', trim($m[0]));
        }, $text);

        $text = (string) preg_replace_callback(self::PLATE_RE, fn($m) => $this->plateMatch($m), $text);

        // a name that is already in the vault (given through the form) is masked when it is typed again
        foreach ($this->map as $ph => $val) {
            if (str_starts_with((string) $ph, '[NAME-') && mb_strlen($val) >= 3) {
                $text = (string) preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($val, '/') . '(?![\p{L}\p{N}])/iu', (string) $ph, $text);
            }
        }

        return $text;
    }

    /** @param array<int, string> $m */
    private function plateMatch(array $m): string
    {
        $lead = ($m[1] ?? '') !== '' ? $m[1] : '';
        $sep = ($m[1] ?? '') !== '' ? ($m[2] ?? '') : ($m[5] ?? '');
        $digits = ($m[1] ?? '') !== '' ? ($m[3] ?? '') : ($m[4] ?? '');
        $letters = $lead !== '' ? $lead : ($m[6] ?? '');
        $upper = $letters === strtoupper($letters);

        if (in_array(strtoupper($letters), self::NOT_PLATE, true)) {
            return $m[0];
        }

        if ($lead !== '') {
            // LV: 2 letters + 3-4 digits, LT: 3 letters + 3 digits
            $ok = (strlen($letters) === 2 && in_array(strlen($digits), [3, 4], true)) || (strlen($letters) === 3 && strlen($digits) === 3);

            if (!$ok || (strlen($letters) === 2 && strlen($digits) === 3 && !$upper)) {
                return $m[0];
            }

            if ($sep === ' ' && (!$upper || preg_match('/^(19|20)\d\d$/', $digits))) {
                return $m[0];
            }
        } elseif ($sep === ' ' && !$upper) {
            return $m[0];
        }

        $plate = strtoupper($m[0]);

        return $this->placeholderFor('PLATE', (string) preg_replace('/[^A-Z0-9]/', '', $plate));
    }

    /** A value given through the details form: its placeholder (the same value keeps the same one). */
    public function add(string $kind, string $value): string
    {
        return $this->placeholderFor($kind, $value);
    }

    /** Is there a value of this kind in the vault? */
    public function has(string $kind): bool
    {
        foreach (array_keys($this->map) as $k) {
            if (str_starts_with((string) $k, '[' . $kind . '-')) {
                return true;
            }
        }

        return false;
    }

    private function inVault(string $kind, string $value): bool
    {
        foreach ($this->map as $ph => $v) {
            if (!str_starts_with((string) $ph, '[' . $kind . '-')) {
                continue;
            }

            if ($kind === 'PHONE' ? preg_replace('/\D+/', '', $v) === preg_replace('/\D+/', '', $value) : mb_strtolower($v) === mb_strtolower($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a tool argument stands for: a placeholder of the right kind -> its real value; a literal value only when it is
     * already in the vault, or the visitor typed exactly this in the chat (`$typed` = the current message plus this chat's
     * earlier questions) - so a model cannot conjure a number, an address, a plate or a name; anything else null.
     */
    public function resolve(string $arg, string $typed, string $kind): ?string
    {
        $arg = trim($arg);

        if (isset($this->map[$arg])) {
            return str_starts_with($arg, '[' . $kind . '-') ? $this->map[$arg] : null;
        }

        if ($arg === '' || preg_match('/^\[(PHONE|EMAIL|NAME|PLATE)-\d+\]$/', $arg)) {
            return null;
        }

        if ($kind === 'PHONE') {
            $d = preg_replace('/\D+/', '', $arg) ?? '';

            return strlen($d) >= 8 && ($this->inVault('PHONE', $arg) || str_contains(preg_replace('/\D+/', '', $typed) ?? '', $d)) ? $arg : null;
        }

        if ($kind === 'PLATE') {
            $n = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $arg));

            if ($this->inVault('PLATE', $n)) {
                return $n;
            }

            return strlen($n) >= 4 && str_contains(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $typed)), $n) ? $n : null;
        }

        if ($this->inVault($kind, $arg)) {
            return $arg;
        }

        return mb_strlen($arg) >= 2 && mb_stripos($typed, $arg) !== false ? $arg : null;
    }
}
