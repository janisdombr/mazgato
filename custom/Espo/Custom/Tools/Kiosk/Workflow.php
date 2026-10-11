<?php

namespace Espo\Custom\Tools\Kiosk;

use Espo\Core\Exceptions\Conflict;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * What a worker may do to an order from the tablet — the rules live HERE, on the server,
 * never in the UI:
 *
 *  - you see every car, but you change only YOUR works and FREE ones. Another worker's
 *    line is refused with 403 `foreign_work` (and the refusal is audited). Reassigning
 *    someone else's work is the operator's job in the CRM.
 *  - "one car, one ramp" and the write-once start/finish stamps are NOT re-implemented:
 *    every status change goes through the normal entity save, so `PreventDuplicateActive`
 *    (409) and `TrackTimes` still decide.
 *  - taking a free line is an atomic `UPDATE … WHERE employee IS NULL`, so two tablets
 *    grabbing the same line at once cannot both win.
 *  - every action is idempotent by nature (done twice = done once) on top of the
 *    `clientActionId` ledger.
 *
 * Money is not touched: `paymentStatus` stays the operator's (status and payment are
 * independent axes).
 */
class Workflow
{
    private const ACTIVE = ['Reserved', 'New', 'In Progress'];

    /** A finished order still accepts photos for this long (the "after" shot, a late damage note). */
    private const PHOTO_GRACE_HOURS = 12;

    public function __construct(
        private EntityManager $entityManager,
        private Queue $queue
    ) {}

    // ---- orders ----------------------------------------------------------------------------

    /**
     * Assign the worker to the free works of the order (their usual role's only, when the
     * employee has one). Lines already taken are never touched.
     *
     * @return array<string, mixed>
     */
    public function takeOrder(Entity $employee, string $orderId): array
    {
        $order = $this->order($orderId);
        $this->requireActive($order);

        $lines = $this->lines($orderId);
        $roleId = $employee->get('workRoleId');
        $taken = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            if ($line->get('employeeId')) {
                continue;
            }

            if ($roleId && $line->get('roleId') !== $roleId) {
                $skipped++;

                continue;
            }

            if ($this->claim($line, $employee)) {
                $taken++;
            } else {
                $skipped++;
            }
        }

        if ($lines && $taken === 0 && !$this->hasLine($lines, $employee)) {
            throw new Deny('nothing_to_take', 409);
        }

        return ['taken' => $taken, 'order' => $this->queue->card($this->order($orderId), $employee, true)];
    }

    /**
     * @return array<string, mixed>
     */
    public function startOrder(Entity $employee, string $orderId): array
    {
        $order = $this->order($orderId);
        $status = (string) $order->get('status');

        if (in_array($status, ['Completed', 'Cancelled'], true)) {
            throw new Deny('order_closed', 409, ['status' => $status]);
        }

        $this->requireAssigned($order, $employee);

        if ($status !== 'In Progress') {
            $order->set('status', 'In Progress');

            try {
                $this->entityManager->saveEntity($order);
            } catch (Conflict $e) {
                // PreventDuplicateActive: the car is already on the ramp under another order.
                throw new Deny('ramp_busy', 409, ['reason' => $e->getMessage()]);
            }
        }

        return ['order' => $this->queue->card($this->order($orderId), $employee, true)];
    }

    /**
     * Complete the order. Refuses while works are still open unless `$force` ("part of the
     * work was not done") — nothing is paid differently then; the operator sees the open
     * lines and the audit meta and decides.
     *
     * @return array{result: array<string, mixed>, meta: array<string, mixed>}
     */
    public function finishOrder(Entity $employee, string $orderId, bool $force): array
    {
        $order = $this->order($orderId);
        $status = (string) $order->get('status');

        if ($status === 'Completed') {
            return [
                'result' => ['order' => $this->queue->card($order, $employee, true), 'already' => true],
                'meta' => ['already' => true],
            ];
        }

        if ($status === 'Cancelled') {
            throw new Deny('order_closed', 409, ['status' => $status]);
        }

        if ($status !== 'In Progress') {
            throw new Deny('not_started', 409, ['status' => $status]);
        }

        $this->requireAssigned($order, $employee);

        $pending = [];

        foreach ($this->lines($orderId) as $line) {
            if (!$line->get('doneAt')) {
                $pending[] = (string) $line->get('name');
            }
        }

        if ($pending && !$force) {
            throw new Deny('works_pending', 409, ['pending' => $pending]);
        }

        $order->set('status', 'Completed');
        $this->entityManager->saveEntity($order);

        return [
            'result' => ['order' => $this->queue->card($this->order($orderId), $employee, true)],
            'meta' => $pending ? ['partial' => true, 'pending' => $pending] : [],
        ];
    }

    // ---- works -----------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function takeWork(Entity $employee, string $lineId): array
    {
        $line = $this->line($lineId);
        $order = $this->order((string) $line->get('washOrderId'));
        $this->requireActive($order);

        $this->ownOrClaim($line, $employee);

        return ['order' => $this->queue->card($order, $employee, true)];
    }

    /**
     * @return array<string, mixed>
     */
    public function startWork(Entity $employee, string $lineId): array
    {
        $line = $this->line($lineId);
        $order = $this->order((string) $line->get('washOrderId'));
        $this->requireRunning($order);

        $line = $this->ownOrClaim($line, $employee);

        if (!$line->get('startedAt')) {
            $line->set('startedAt', gmdate('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($line);
        }

        return ['order' => $this->queue->card($order, $employee, true)];
    }

    /**
     * @return array<string, mixed>
     */
    public function workDone(Entity $employee, User $device, string $lineId): array
    {
        $line = $this->line($lineId);
        $order = $this->order((string) $line->get('washOrderId'));
        $this->requireRunning($order);

        $line = $this->ownOrClaim($line, $employee);
        $already = (bool) $line->get('doneAt');

        if (!$already) {
            $now = gmdate('Y-m-d H:i:s');

            $line->set([
                'doneAt' => $now,
                'doneById' => $employee->getId(),
                'doneDeviceId' => $device->getId(),
            ]);

            if (!$line->get('startedAt')) {
                $line->set('startedAt', $now);
            }

            $this->entityManager->saveEntity($line);
        }

        return ['already' => $already, 'order' => $this->queue->card($order, $employee, true)];
    }

    /**
     * Take back a "done" — only by whoever did the work, and only while the order is open.
     *
     * @return array<string, mixed>
     */
    public function workUndone(Entity $employee, string $lineId): array
    {
        $line = $this->line($lineId);
        $order = $this->order((string) $line->get('washOrderId'));
        $this->requireRunning($order);

        if ($line->get('employeeId') !== $employee->getId() && $line->get('doneById') !== $employee->getId()) {
            throw new Deny('foreign_work', 403, ['employee' => $this->nameOf($line->get('employeeId'))]);
        }

        if ($line->get('doneAt')) {
            $line->set(['doneAt' => null, 'doneById' => null, 'doneDeviceId' => null]);
            $this->entityManager->saveEntity($line);
        }

        return ['order' => $this->queue->card($order, $employee, true)];
    }

    // ---- photos ----------------------------------------------------------------------------

    /** The order a photo may be attached to by this worker; throws otherwise. */
    public function orderForPhoto(Entity $employee, string $orderId): Entity
    {
        $order = $this->order($orderId);
        $status = (string) $order->get('status');

        if ($status === 'Cancelled') {
            throw new Deny('order_closed', 409, ['status' => $status]);
        }

        if ($status === 'Completed') {
            $finished = strtotime((string) $order->get('finishedAt'));

            if (!$finished || $finished < time() - self::PHOTO_GRACE_HOURS * 3600) {
                throw new Deny('order_closed', 409, ['status' => $status]);
            }
        }

        $this->requireAssigned($order, $employee);

        return $order;
    }

    // ---- guards ----------------------------------------------------------------------------

    private function order(string $id): Entity
    {
        $order = $id ? $this->entityManager->getEntityById('WashOrder', $id) : null;

        if (!$order) {
            throw new Deny('not_found', 404);
        }

        return $order;
    }

    private function line(string $id): Entity
    {
        $line = $id ? $this->entityManager->getEntityById('OrderWork', $id) : null;

        if (!$line) {
            throw new Deny('not_found', 404);
        }

        return $line;
    }

    /** @return Entity[] */
    private function lines(string $orderId): array
    {
        return iterator_to_array(
            $this->entityManager->getRDBRepository('OrderWork')
                ->where(['washOrderId' => $orderId, 'deleted' => false])
                ->order('createdAt', 'ASC')
                ->find(),
            false
        );
    }

    /** @param Entity[] $lines */
    private function hasLine(array $lines, Entity $employee): bool
    {
        foreach ($lines as $line) {
            if ($line->get('employeeId') === $employee->getId()) {
                return true;
            }
        }

        return false;
    }

    private function requireActive(Entity $order): void
    {
        if (!in_array((string) $order->get('status'), self::ACTIVE, true)) {
            throw new Deny('order_closed', 409, ['status' => (string) $order->get('status')]);
        }
    }

    private function requireRunning(Entity $order): void
    {
        $status = (string) $order->get('status');

        if ($status === 'In Progress') {
            return;
        }

        throw new Deny(in_array($status, ['Completed', 'Cancelled'], true) ? 'order_closed' : 'not_started', 409, ['status' => $status]);
    }

    /**
     * The worker is on the order if one of its works is theirs. An order with no works at
     * all has nobody to protect, so any signed-in worker may run it.
     */
    private function requireAssigned(Entity $order, Entity $employee): void
    {
        $lines = $this->lines($order->getId());

        if ($lines && !$this->hasLine($lines, $employee)) {
            throw new Deny('not_assigned', 403);
        }
    }

    /**
     * My line stays mine; a free line becomes mine (atomically); someone else's is refused.
     */
    private function ownOrClaim(Entity $line, Entity $employee): Entity
    {
        $owner = $line->get('employeeId');

        if ($owner === $employee->getId()) {
            return $line;
        }

        if ($owner) {
            throw new Deny('foreign_work', 403, ['employee' => $this->nameOf($owner)]);
        }

        if (!$this->claim($line, $employee)) {
            $fresh = $this->entityManager->getEntityById('OrderWork', $line->getId());
            $owner = $fresh ? $fresh->get('employeeId') : null;

            throw new Deny('foreign_work', 403, ['employee' => $this->nameOf($owner)]);
        }

        return $this->line($line->getId());
    }

    /**
     * Atomic claim of a free line. Afterwards the line is saved once more through the
     * normal path so the rate matrix is re-read for THIS employee (an employee-specific
     * WorkRate cell) — unless the amount was typed by hand, which is never recomputed.
     */
    private function claim(Entity $line, Entity $employee): bool
    {
        $query = $this->entityManager->getQueryBuilder()
            ->update()
            ->in('OrderWork')
            ->set(['employeeId' => $employee->getId()])
            ->where(['id' => $line->getId(), 'employeeId' => null, 'deleted' => false])
            ->build();

        $stmt = $this->entityManager->getQueryExecutor()->execute($query);

        if ($stmt->rowCount() < 1) {
            return false;
        }

        $fresh = $this->entityManager->getEntityById('OrderWork', $line->getId());

        if ($fresh && !$fresh->get('amountManual')) {
            $fresh->set('rate', null);
            $this->entityManager->saveEntity($fresh);
        }

        return true;
    }

    private function nameOf(?string $employeeId): string
    {
        $e = $employeeId ? $this->entityManager->getEntityById('Employee', $employeeId) : null;

        return $e ? (string) $e->get('name') : '';
    }
}
