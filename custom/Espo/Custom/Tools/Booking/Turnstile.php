<?php

namespace Espo\Custom\Tools\Booking;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Assistant\Secret;

/**
 * Cloudflare Turnstile for the public booking form (optional, off until a SECRET is stored).
 *
 *  - `turnstileSiteKey`  public; goes to the browser through Booking/action/config (only together with the secret).
 *  - `turnstileSecret`   config level "internal" (data/config-internal.php), write-only on #Admin/booking, held as a
 *                        Secret, never returned, never logged.
 *  - `turnstileHostname` optional expected hostname of the answer; empty = the host of `siteUrl`.
 *
 * verify() is CLOSED: a missing, wrong or reused token is `failed`; Cloudflare unreachable / slow (4 s) / redirecting / answering
 * rubbish is `unavailable` - the form is never opened to bots because a third party is down. The reply of Cloudflare is
 * never passed on. Test mode (`bin/command turnstile-test-mode on --base=URL`) is the only way to point it elsewhere.
 */
class Turnstile
{
    public const URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    public const OK = '';
    public const FAILED = 'failed';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(private Config $config, private Log $log) {}

    public function secret(): Secret
    {
        return new Secret(trim((string) $this->config->get('turnstileSecret', '')));
    }

    public function siteKey(): string
    {
        $k = trim((string) $this->config->get('turnstileSiteKey', ''));

        return preg_match('/^[0-9A-Za-z_-]{6,80}$/', $k) ? $k : '';
    }

    /** On = a secret is stored. The widget is offered to visitors only when the site key is valid too. */
    public function enabled(): bool
    {
        return $this->secret()->isSet();
    }

    public function widgetKey(): string
    {
        return $this->enabled() ? $this->siteKey() : '';
    }

    private function testMode(): bool
    {
        return $this->config->get('turnstileTestMode') === true;
    }

    private function endpoint(): string
    {
        $b = trim((string) $this->config->get('turnstileVerifyUrl', ''));

        // another endpoint is for the test mock only: the secret must never be sent to an arbitrary host
        return $this->testMode() && preg_match('#^https?://[^\s]+$#', $b) ? $b : self::URL;
    }

    private function expectedHost(): string
    {
        $h = strtolower(trim((string) $this->config->get('turnstileHostname', '')));

        if ($h !== '') {
            return $h;
        }

        return strtolower((string) (parse_url((string) $this->config->get('siteUrl', ''), PHP_URL_HOST) ?: ''));
    }

    /** @return string self::OK | self::FAILED | self::UNAVAILABLE */
    public function verify(string $token, string $ip): string
    {
        $secret = $this->secret();
        $token = trim($token);

        if ($token === '' || strlen($token) > 2048) {
            return self::FAILED;
        }

        $fields = ['secret' => $secret->reveal(), 'response' => $token, 'idempotency_key' => $this->uuid()];

        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $fields['remoteip'] = $ip;
        }

        $url = $this->endpoint();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => $this->testMode() ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($body === false || $errno !== 0 || $code !== 200) {
            $this->log->warning('Turnstile: verification unavailable (closed), curl=' . $errno . ' http=' . $code);

            return self::UNAVAILABLE;
        }

        $data = json_decode((string) $body, true);

        if (!is_array($data)) {
            $this->log->warning('Turnstile: unreadable answer (closed)');

            return self::UNAVAILABLE;
        }

        if (($data['success'] ?? false) !== true) {
            return self::FAILED;
        }

        $expect = $this->expectedHost();

        if ($expect !== '' && strtolower((string) ($data['hostname'] ?? '')) !== $expect) {
            return self::FAILED;
        }

        return self::OK;
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
