<?php

namespace Espo\Custom\Tools\Licensing;

/**
 * Pure verification of a signed licence file - no Espo dependencies, so it can be tested alone.
 *
 * File (JSON):   {"format":"cwlic1","payload":"<base64 of the payload JSON>","signature":"<base64 Ed25519>"}
 * The signature is over the RAW payload bytes (not a re-serialisation), so there is no
 * canonical-JSON ambiguity. Payload:
 *   { "customer": str, "installationId": str, "modules": [str], "issued": "YYYY-MM-DD",
 *     "expires": "YYYY-MM-DD", "grace": int days }
 *
 * Status: valid | grace | expired | bad_signature | wrong_installation | malformed | no_key | missing
 * `valid` and `grace` mean "modules apply"; the rest mean "none do".
 * Nothing here talks to the network: verification is offline, with the public key only.
 */
class LicenseVerifier
{
    public const FORMAT = 'cwlic1';

    /**
     * @return array{status: string, payload: ?array<string, mixed>, expiresAt: ?int, graceUntil: ?int}
     */
    public static function check(?string $fileJson, string $publicKeyB64, string $installationId, int $now): array
    {
        $none = fn(string $s, ?array $p = null, ?int $e = null, ?int $g = null) =>
            ['status' => $s, 'payload' => $p, 'expiresAt' => $e, 'graceUntil' => $g];

        if ($fileJson === null || trim($fileJson) === '') {
            return $none('missing');
        }

        $file = json_decode($fileJson, true);

        if (!is_array($file) || ($file['format'] ?? null) !== self::FORMAT
            || !is_string($file['payload'] ?? null) || !is_string($file['signature'] ?? null)) {
            return $none('malformed');
        }

        $raw = base64_decode($file['payload'], true);
        $sig = base64_decode($file['signature'], true);

        if ($raw === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return $none('malformed');
        }

        $key = base64_decode($publicKeyB64, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $none('no_key');
        }

        if (!sodium_crypto_sign_verify_detached($sig, $raw, $key)) {
            return $none('bad_signature');
        }

        $p = json_decode($raw, true);

        if (!is_array($p) || !is_string($p['installationId'] ?? null) || !is_array($p['modules'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['expires'] ?? ''))) {
            return $none('malformed');
        }

        $p['modules'] = array_values(array_filter($p['modules'], 'is_string'));

        if (!hash_equals($p['installationId'], $installationId)) {
            return $none('wrong_installation', $p);
        }

        $expiresAt = strtotime($p['expires'] . ' 23:59:59 UTC');
        $graceUntil = $expiresAt + max(0, (int) ($p['grace'] ?? 0)) * 86400;

        if ($now <= $expiresAt) {
            return $none('valid', $p, $expiresAt, $graceUntil);
        }

        return $none($now <= $graceUntil ? 'grace' : 'expired', $p, $expiresAt, $graceUntil);
    }
}
