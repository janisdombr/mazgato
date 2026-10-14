<?php

namespace Espo\Custom\Tools\ClientAssistant;

/**
 * The visitor's booking details as the widget's details form sends them: name, phone, plate, e-mail, promo code, referral code.
 * Validation is HARD and server-side (the form's own checks are a convenience): a value that is too long, holds control
 * characters, markup brackets or placeholder look-alikes, or is not the right shape is refused as a whole - nothing is
 * trimmed to fit, nothing is stored, nothing reaches the model.
 *
 * Name, phone, plate and e-mail go into the ContactVault (the model only ever sees [NAME-1] ...). The promo and referral
 * codes are codes, not personal data; they are passed to the model as they are, in the one line `line()` builds.
 * No Espo dependency: unit-tested on its own (tests/client-assistant.test.php).
 */
final class Details
{
    public const MAX = ['name' => 60, 'phone' => 24, 'plate' => 12, 'email' => 100, 'promo' => 40, 'referral' => 20];

    /**
     * @param mixed $in  decoded JSON (array or object) or anything else
     * @return array{ok: bool, values: array<string, string>, fields: string[]}
     */
    public static function validate(mixed $in, bool $promoOn, bool $referralOn): array
    {
        $in = is_object($in) ? (array) $in : $in;

        if (!is_array($in)) {
            return ['ok' => false, 'values' => [], 'fields' => []];
        }

        $v = [];
        $bad = [];

        foreach (self::MAX as $k => $max) {
            $x = $in[$k] ?? '';
            $x = is_int($x) || is_float($x) ? (string) $x : $x;

            if (!is_string($x)) {
                $bad[] = $k;
                continue;
            }

            $x = trim($x);

            if (preg_match('/[\x00-\x1F\x7F]/', $x) || mb_strlen($x) > $max) {
                $bad[] = $k;
                continue;
            }

            $v[$k] = $x;
        }

        $name = preg_replace('/\s+/u', ' ', $v['name'] ?? '') ?? '';

        if (!in_array('name', $bad, true) && (mb_strlen($name) < 2 || !preg_match('/^[\p{L}\p{N}\p{M} .,\'’"&()\-]+$/u', $name) || !preg_match('/\p{L}/u', $name))) {
            $bad[] = 'name';
        }

        $v['name'] = $name;

        if (!in_array('phone', $bad, true)) {
            $digits = preg_replace('/\D+/', '', $v['phone']) ?? '';

            if (!preg_match('/^\+?[\d\s().\-]+$/', $v['phone']) || strlen($digits) < 8 || strlen($digits) > 15) {
                $bad[] = 'phone';
            }
        }

        if (!in_array('plate', $bad, true)) {
            $plate = strtoupper((string) preg_replace('/[ \-]/', '', $v['plate']));

            if (!preg_match('/^[A-Z0-9]{2,10}$/', $plate)) {
                $bad[] = 'plate';
            }

            $v['plate'] = $plate;
        }

        if (!in_array('email', $bad, true) && $v['email'] !== '') {
            if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[<>\[\]\s]/', $v['email'])) {
                $bad[] = 'email';
            }

            $v['email'] = mb_strtolower($v['email']);
        }

        // codes: only while the matching feature is offered; an unexpected one is refused, not silently dropped
        if (!in_array('promo', $bad, true) && $v['promo'] !== '' && (!$promoOn || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-_]*$/', $v['promo']))) {
            $bad[] = 'promo';
        }

        if (!in_array('referral', $bad, true) && $v['referral'] !== '' && (!$referralOn || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]*$/', $v['referral']))) {
            $bad[] = 'referral';
        }

        // required: name, phone, plate
        foreach (['name', 'phone', 'plate'] as $req) {
            if (($v[$req] ?? '') === '' && !in_array($req, $bad, true)) {
                $bad[] = $req;
            }
        }

        return ['ok' => !$bad, 'values' => $v, 'fields' => array_values(array_unique($bad))];
    }

    /**
     * Put the personal values into the vault and build the message the model is told the visitor sent. Placeholders only
     * for name / phone / plate / e-mail; the two codes as typed.
     *
     * @param array<string, string> $v validated values
     */
    public static function line(array $v, ContactVault $vault): string
    {
        $parts = [
            'name ' . $vault->add('NAME', $v['name']),
            'phone ' . $vault->add('PHONE', $v['phone']),
            'plate ' . $vault->add('PLATE', $v['plate']),
        ];

        if (($v['email'] ?? '') !== '') {
            $parts[] = 'e-mail ' . $vault->add('EMAIL', $v['email']);
        }

        if (($v['promo'] ?? '') !== '') {
            $parts[] = 'promo code ' . $v['promo'];
        }

        if (($v['referral'] ?? '') !== '') {
            $parts[] = 'referral code ' . $v['referral'];
        }

        return 'Details sent through the form: ' . implode(', ', $parts) . '.';
    }
}
