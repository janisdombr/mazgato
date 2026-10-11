<?php

namespace Espo\Custom\Hooks\OrderWork;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Fill an order-work line and price it.
 *
 *  - `role`     — defaults to the work's default role when left empty.
 *  - `rate`     — prefilled from the WorkRate matrix (Work × Service × Employee)
 *                 only while empty, so an amount typed in the order is always kept.
 *                 Clear the field to re-resolve.
 *  - `amount`   — always computed = rate × share%. Partial completion is expressed by
 *                 repeating the same work on several lines, each carrying its share.
 *
 * Rate resolution order (first hit wins):
 *   1. work + service + employee   — the exact cell
 *   2. work + service              — same for every employee
 *   3. work + employee             — regardless of the service
 *   4. work                        — a single rate for the work
 *   5. Work.baseRate               — the fallback stored on the work itself
 */
class CalculateAmount
{
    public static $order = 8;

    public function __construct(private EntityManager $entityManager)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $workId = $entity->get('workId');

        if (!$workId) {
            return;
        }

        $work = $this->entityManager->getEntityById('Work', $workId);

        if (!$entity->get('name') && $work) {
            $entity->set('name', $work->get('name'));
        }

        if (!$entity->get('roleId') && $work && $work->get('defaultRoleId')) {
            $entity->set('roleId', $work->get('defaultRoleId'));
        }

        if ($entity->get('rate') === null || $entity->get('rate') === '') {
            $rate = $this->resolveRate($workId, $entity->get('serviceId'), $entity->get('employeeId'), $work);

            if ($rate !== null) {
                $entity->set('rate', $rate);
                $entity->set('rateCurrency', $entity->get('rateCurrency') ?: 'EUR');
            }
        }

        $share = $entity->get('share');
        $share = ($share === null || $share === '') ? 100.0 : (float) $share;

        // The amount is typed straight into the order's works panel, so a hand-entered
        // value must survive every later save — not just this one. `amountManual`
        // records that choice; editing the rate or the share hands control back to the
        // automatic rate x share.
        if ($entity->isAttributeChanged('rate') || $entity->isAttributeChanged('share')) {
            $entity->set('amountManual', false);
        } elseif ($entity->isAttributeChanged('amount') && $entity->get('amount') !== null) {
            $entity->set('amountManual', true);
        }

        if (!$entity->get('amountManual')) {
            $entity->set('amount', round((float) $entity->get('rate') * $share / 100, 2));
        }

        $entity->set('amountCurrency', $entity->get('rateCurrency') ?: 'EUR');
    }

    private function resolveRate(string $workId, ?string $serviceId, ?string $employeeId, ?Entity $work): ?float
    {
        $candidates = [];

        if ($serviceId && $employeeId) {
            $candidates[] = ['workId' => $workId, 'serviceId' => $serviceId, 'employeeId' => $employeeId];
        }

        if ($serviceId) {
            $candidates[] = ['workId' => $workId, 'serviceId' => $serviceId, 'employeeId' => null];
        }

        if ($employeeId) {
            $candidates[] = ['workId' => $workId, 'serviceId' => null, 'employeeId' => $employeeId];
        }

        $candidates[] = ['workId' => $workId, 'serviceId' => null, 'employeeId' => null];

        foreach ($candidates as $where) {
            $row = $this->entityManager
                ->getRDBRepository('WorkRate')
                ->where($where)
                ->findOne();

            if ($row && $row->get('rate') !== null) {
                return (float) $row->get('rate');
            }
        }

        if ($work && $work->get('baseRate') !== null) {
            return (float) $work->get('baseRate');
        }

        return null;
    }
}
