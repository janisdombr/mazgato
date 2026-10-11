<?php

namespace Espo\Custom\Tools\Anpr;

use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Kiosk\Deny;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Who an edge node is. A node (the small PC at the wash) holds one secret token:
 *
 *   enrolment   Administration issues a node and shows a ONE-TIME enrolment token (valid
 *               `ENROLL_HOURS`, QR + text). The node posts it to `Anpr/action/register` and gets
 *               its own long-lived token back; the enrolment token is spent (its hash is wiped).
 *   operation   every later request carries `X-Edge-Token: cwe1.<nodeId>.<secret>`. Only an
 *               HMAC of the secret (keyed from the instance `cryptKey`) is stored, compared in
 *               constant time; the token itself is never stored, logged or returned again.
 *   revocation  status Revoked -> 401 at once; nothing else is affected.
 *
 * A dedicated entity rather than an API user: the node has no user, no password and no role to
 * misuse, and its key opens ONLY the edge endpoints (they check this token, not a session).
 */
class EdgeAuth
{
    public const ENROLL_PREFIX = 'cwen1';
    public const TOKEN_PREFIX = 'cwe1';
    public const ENROLL_HOURS = 24;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    /**
     * @return array{node: Entity, enrollToken: string}
     */
    public function issue(string $name): array
    {
        $secret = bin2hex(random_bytes(20));

        $node = $this->entityManager->createEntity('EdgeNode', [
            'name' => $name,
            'status' => 'Pending',
            'enrollHash' => $this->hash($secret),
            'enrollExpiresAt' => gmdate('Y-m-d H:i:s', time() + self::ENROLL_HOURS * 3600),
        ]);

        return ['node' => $node, 'enrollToken' => self::ENROLL_PREFIX . '.' . $node->getId() . '.' . $secret];
    }

    /**
     * Exchange the one-time enrolment token for the node's own token.
     *
     * @return array{node: Entity, token: string}
     * @throws Deny
     */
    public function enroll(string $enrollToken): array
    {
        [$node, $secret] = $this->parse($enrollToken, self::ENROLL_PREFIX);

        if (!$node || $node->get('status') !== 'Pending' || !$node->get('enrollHash') || !hash_equals((string) $node->get('enrollHash'), $this->hash($secret))) {
            throw new Deny('invalid_enroll_token', 401);
        }

        if ($node->get('enrollExpiresAt') && strtotime($node->get('enrollExpiresAt') . ' UTC') < time()) {
            throw new Deny('enroll_token_expired', 401);
        }

        $tokenSecret = bin2hex(random_bytes(32));

        $node->set([
            'tokenHash' => $this->hash($tokenSecret),
            'enrollHash' => null,
            'enrollExpiresAt' => null,
            'status' => 'Active',
            'enrolledAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($node, ['silent' => true]);

        return ['node' => $node, 'token' => self::TOKEN_PREFIX . '.' . $node->getId() . '.' . $tokenSecret];
    }

    /**
     * @throws Deny
     */
    public function authenticate(?string $header): Entity
    {
        [$node, $secret] = $this->parse((string) $header, self::TOKEN_PREFIX);

        if (!$node || !$node->get('tokenHash') || !hash_equals((string) $node->get('tokenHash'), $this->hash($secret))) {
            throw new Deny('invalid_token', 401);
        }

        if ($node->get('status') === 'Revoked') {
            throw new Deny('token_revoked', 401);
        }

        if ($node->get('status') !== 'Active') {
            throw new Deny('invalid_token', 401);
        }

        return $node;
    }

    public function revoke(Entity $node): void
    {
        $node->set(['status' => 'Revoked', 'tokenHash' => null, 'enrollHash' => null, 'enrollExpiresAt' => null]);
        $this->entityManager->saveEntity($node, ['silent' => true]);
    }

    /**
     * @return array{0: ?Entity, 1: string}
     */
    private function parse(string $token, string $prefix): array
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 3 || $parts[0] !== $prefix || !preg_match('/^[A-Za-z0-9]{10,24}$/', $parts[1]) || !preg_match('/^[0-9a-f]{40,80}$/', $parts[2])) {
            return [null, ''];
        }

        $node = $this->entityManager->getEntityById('EdgeNode', $parts[1]);

        return [$node, $parts[2]];
    }

    private function hash(string $secret): string
    {
        $key = hash_hmac('sha256', 'cw-edge-token-v1', (string) $this->config->get('cryptKey'));

        return hash_hmac('sha256', $secret, $key);
    }
}
