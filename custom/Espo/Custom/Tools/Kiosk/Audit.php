<?php

namespace Espo\Custom\Tools\Kiosk;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The tablet's audit trail AND its idempotency ledger, in one table (`KioskEvent`).
 *
 * Why an entity and not fields on the order: the question an audit answers is "who did
 * what, from which device, when, and what was refused" — including the attempts that
 * changed nothing (a wrong PIN, someone else's work). That is a log, not a property of
 * an order. The same row stores the reply, so a client that resends an action (the
 * offline queue, a double tap) gets the SAME answer back instead of a second effect:
 * `clientActionId` is unique.
 *
 * Rows are never edited (`Hooks/KioskEvent/Immutable`).
 */
class Audit
{
    public function __construct(private EntityManager $entityManager)
    {}

    public function find(string $clientActionId): ?Entity
    {
        return $this->entityManager->getRDBRepository('KioskEvent')
            ->where(['clientActionId' => $clientActionId, 'deleted' => false])
            ->findOne();
    }

    /**
     * @param array<string, mixed> $a action, outcome, errorCode, httpStatus, employeeId, deviceId,
     *   washOrderId, orderWorkId, clientActionId, clientAt, meta (array), result (array), ip
     * @return ?Entity null when the unique client id was taken a moment ago by a twin request
     */
    public function record(array $a): ?Entity
    {
        $attrs = [
            'name' => ($a['action'] ?? '?') . ' · ' . ($a['outcome'] ?? 'Ok'),
            'action' => $a['action'] ?? null,
            'outcome' => $a['outcome'] ?? 'Ok',
            'errorCode' => $a['errorCode'] ?? null,
            'httpStatus' => $a['httpStatus'] ?? 200,
            'employeeId' => $a['employeeId'] ?? null,
            'deviceId' => $a['deviceId'] ?? null,
            'washOrderId' => $a['washOrderId'] ?? null,
            'orderWorkId' => $a['orderWorkId'] ?? null,
            'clientActionId' => $a['clientActionId'] ?? null,
            'clientAt' => $this->clientTime($a['clientAt'] ?? null),
            'meta' => !empty($a['meta']) ? json_encode($a['meta'], JSON_UNESCAPED_UNICODE) : null,
            'resultJson' => isset($a['result']) ? json_encode($a['result'], JSON_UNESCAPED_UNICODE) : null,
            'ip' => $a['ip'] ?? null,
        ];

        try {
            return $this->entityManager->createEntity('KioskEvent', $attrs, ['silent' => true]);
        } catch (\Throwable $e) {
            // The unique index on clientActionId: a parallel twin got in first.
            if ($attrs['clientActionId'] && $this->find($attrs['clientActionId'])) {
                return null;
            }

            throw $e;
        }
    }

    public function noteReplay(Entity $event): void
    {
        $event->set('replayCount', (int) $event->get('replayCount') + 1);
        $this->entityManager->saveEntity($event, ['silent' => true, 'allowAuditUpdate' => true]);
    }

    /** Events from this device in the last `$seconds` (every outcome counts: spam is spam). */
    public function countRecent(string $deviceId, int $seconds): int
    {
        return $this->entityManager->getRDBRepository('KioskEvent')
            ->where([
                'deviceId' => $deviceId,
                'createdAt>=' => gmdate('Y-m-d H:i:s', time() - $seconds),
            ])
            ->count();
    }

    /** Events of one kind from this device in the last `$seconds`. */
    public function countRecentOf(string $deviceId, string $action, int $seconds): int
    {
        return $this->entityManager->getRDBRepository('KioskEvent')
            ->where([
                'deviceId' => $deviceId,
                'action' => $action,
                'createdAt>=' => gmdate('Y-m-d H:i:s', time() - $seconds),
            ])
            ->count();
    }

    /** Client clocks lie: keep a parseable time within a day of now, else drop it. */
    private function clientTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $t = strtotime($value);

        if ($t === false || abs($t - time()) > 86400 * 2) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $t);
    }
}
