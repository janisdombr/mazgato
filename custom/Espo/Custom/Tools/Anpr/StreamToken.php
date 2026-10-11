<?php

namespace Espo\Custom\Tools\Anpr;

use Espo\Core\Utils\Config;

/**
 * The short-lived pass for the live viewer. The reverse proxy asks the CRM (`auth_request` ->
 * entry point `camAuth`) before it lets a request through to go2rtc / Frigate; this is what the
 * CRM checks. A pass is `<base64url(json)>.<hmac>`: who (user id), which go2rtc stream, until
 * when (`TTL`). It travels in a cookie scoped to the viewer's path (the iframe and the viewer's
 * own WebSocket then carry it without any URL juggling) or in `?token=`. Stateless: nothing is
 * stored, a pass cannot be extended, and it names exactly one stream.
 */
class StreamToken
{
    public const TTL = 300;
    public const COOKIE = 'cwcam';

    public function __construct(private Config $config)
    {}

    /** @return array{token: string, expiresAt: int} */
    public function issue(string $userId, string $stream): array
    {
        $exp = time() + self::TTL;
        $body = rtrim(strtr(base64_encode((string) json_encode(['u' => $userId, 's' => $stream, 'e' => $exp])), '+/', '-_'), '=');

        return ['token' => $body . '.' . $this->sign($body), 'expiresAt' => $exp];
    }

    /** @return ?array{u: string, s: string, e: int} */
    public function verify(?string $token): ?array
    {
        if (!$token || substr_count($token, '.') !== 1) {
            return null;
        }

        [$body, $sig] = explode('.', $token);

        if (!hash_equals($this->sign($body), $sig)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);

        if (!is_array($data) || !isset($data['u'], $data['s'], $data['e']) || (int) $data['e'] < time()) {
            return null;
        }

        return ['u' => (string) $data['u'], 's' => (string) $data['s'], 'e' => (int) $data['e']];
    }

    private function sign(string $body): string
    {
        $key = hash_hmac('sha256', 'cw-cam-stream-v1', (string) $this->config->get('cryptKey'));

        return hash_hmac('sha256', $body, $key);
    }
}
