<?php

namespace Espo\Custom\Tools\Kiosk;

use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * A worker's PIN sign-in on one device. The token is 32 random bytes handed out once;
 * only its SHA-256 is stored (like an API key's twin), so the table cannot be replayed.
 * Bound to the device that created it — another tablet's key plus this token is useless.
 *
 * It is an entity rather than a signed token so "who is signed in where" is visible and a
 * session can be revoked. The lifetime is long on purpose (`kioskSessionHours`, 12 h): a
 * worker who signed out while the Wi-Fi was down still has queued actions to deliver
 * under that session; the tablet itself returns to the PIN screen after ~60 s idle.
 */
class SessionManager
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function hours(): int
    {
        return max(1, (int) ($this->config->get('kioskSessionHours') ?: 12));
    }

    /**
     * @return array{token: string, session: Entity}
     */
    public function start(Entity $employee, User $device, ?string $ip): array
    {
        $now = gmdate('Y-m-d H:i:s');

        // One live sign-in per worker per device.
        foreach ($this->openFor($employee->getId(), $device->getId()) as $old) {
            $this->end($old, 'Replaced');
        }

        $token = bin2hex(random_bytes(32));

        $session = $this->entityManager->createEntity('KioskSession', [
            'name' => $employee->get('name') . ' · ' . substr($now, 0, 16),
            'employeeId' => $employee->getId(),
            'deviceId' => $device->getId(),
            'tokenHash' => hash('sha256', $token),
            'startedAt' => $now,
            'lastSeenAt' => $now,
            'expiresAt' => gmdate('Y-m-d H:i:s', time() + $this->hours() * 3600),
            'ip' => $ip,
        ], ['silent' => true]);

        return ['token' => $token, 'session' => $session];
    }

    /**
     * The live session for this token on this device, or null (unknown, ended, expired,
     * wrong device, or the worker is no longer Hired).
     */
    public function resolve(string $token, User $device): ?Entity
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $session = $this->entityManager->getRDBRepository('KioskSession')
            ->where(['tokenHash' => hash('sha256', $token), 'deleted' => false])
            ->findOne();

        if (!$session || $session->get('endedAt') || $session->get('deviceId') !== $device->getId()) {
            return null;
        }

        $now = gmdate('Y-m-d H:i:s');

        if ($session->get('expiresAt') <= $now) {
            $this->end($session, 'Expired');

            return null;
        }

        $employee = $this->entityManager->getEntityById('Employee', (string) $session->get('employeeId'));

        if (!$employee || $employee->get('status') !== 'Hired') {
            $this->end($session, 'Revoked');

            return null;
        }

        // Touch at most every 30 s: it is bookkeeping, not a write per click.
        if (strtotime((string) $session->get('lastSeenAt')) < time() - 30) {
            $session->set('lastSeenAt', $now);
            $this->entityManager->saveEntity($session, ['silent' => true]);
        }

        return $session;
    }

    public function end(Entity $session, string $reason): void
    {
        if ($session->get('endedAt')) {
            return;
        }

        $session->set(['endedAt' => gmdate('Y-m-d H:i:s'), 'endReason' => $reason]);
        $this->entityManager->saveEntity($session, ['silent' => true]);
    }

    /** Sign-out by token. Idempotent: an unknown or already ended token is simply "signed out". */
    public function endByToken(string $token, User $device, string $reason): ?Entity
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $session = $this->entityManager->getRDBRepository('KioskSession')
            ->where(['tokenHash' => hash('sha256', $token), 'deleted' => false])
            ->findOne();

        if (!$session || $session->get('deviceId') !== $device->getId()) {
            return null;
        }

        $this->end($session, $reason);

        return $session;
    }

    /** End every open session of a device (revoke). */
    public function endAllForDevice(string $deviceId, string $reason): int
    {
        $n = 0;

        foreach ($this->openFor(null, $deviceId) as $session) {
            $this->end($session, $reason);
            $n++;
        }

        return $n;
    }

    public function lastSeen(string $deviceId): ?string
    {
        $row = $this->entityManager->getRDBRepository('KioskSession')
            ->select(['id', 'lastSeenAt'])
            ->where(['deviceId' => $deviceId, 'deleted' => false])
            ->order('lastSeenAt', 'DESC')
            ->findOne();

        return $row ? $row->get('lastSeenAt') : null;
    }

    /** @return iterable<Entity> */
    private function openFor(?string $employeeId, string $deviceId): iterable
    {
        $where = ['deviceId' => $deviceId, 'endedAt' => null, 'deleted' => false];

        if ($employeeId) {
            $where['employeeId'] = $employeeId;
        }

        return $this->entityManager->getRDBRepository('KioskSession')->where($where)->find();
    }
}
