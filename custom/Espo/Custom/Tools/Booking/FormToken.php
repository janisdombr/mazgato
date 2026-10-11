<?php

namespace Espo\Custom\Tools\Booking;

use Espo\Core\Utils\Config;

/**
 * Signed, time-boxed form token handed out by Booking/action/config. A create must carry a
 * token that is (a) signed with the installation's secret, (b) older than a few seconds (a
 * human needs longer to fill the form than a script), (c) younger than a few hours.
 * Stateless: ts.nonce.hmac.
 */
class FormToken
{
    public function __construct(private Config $config) {}

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) $this->config->get('cryptKey', 'x') . '|booking');
    }

    public function issue(): string
    {
        $p = time() . '.' . bin2hex(random_bytes(6));

        return $p . '.' . $this->sign($p);
    }

    /** @return string '' when valid, otherwise a reason code */
    public function check(string $token, int $minAge, int $maxAge = 10800): string
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || !hash_equals($this->sign($parts[0] . '.' . $parts[1]), $parts[2])) {
            return 'bad-token';
        }

        $age = time() - (int) $parts[0];

        if ($age < $minAge) {
            return 'too-fast';
        }

        return $age > $maxAge ? 'expired' : '';
    }
}
