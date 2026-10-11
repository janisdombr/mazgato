<?php

namespace Espo\Custom\Hooks\Shift;

use Espo\Core\Exceptions\Conflict;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * A shift is the record of somebody clocking in and out.
 *
 *  - `hours` is computed from the start/finish stamps;
 *  - the employee's `onShift` / `shiftStartedAt` mirror the open shift, so the current
 *    state is visible on the employee without a subquery;
 *  - an employee cannot have two shifts open at once.
 *
 * The employee is saved with skipHooks — it carries no logic of its own here and this
 * avoids a save loop.
 */
class SyncEmployee
{
    public static $order = 9;

    public function __construct(private EntityManager $entityManager)
    {}

    /**
     * @throws Conflict
     */
    public function beforeSave(Entity $entity, array $options = []): void
    {
        $employeeId = $entity->get('employeeId');

        if (!$employeeId) {
            return;
        }

        $start = $entity->get('startedAt');

        if (!$start) {
            $entity->set('startedAt', $start = date('Y-m-d H:i:s'));
        }

        if (!$entity->get('name')) {
            $entity->set('name', substr((string) $start, 0, 16));
        }

        // Refuse a second open shift for the same person.
        if (!$entity->get('finishedAt')) {
            $builder = $this->entityManager
                ->getRDBRepository('Shift')
                ->where([
                    'employeeId' => $employeeId,
                    'finishedAt' => null,
                    'deleted' => false,
                ]);

            if ($entity->getId()) {
                $builder = $builder->where(['id!=' => $entity->getId()]);
            }

            if ($builder->findOne()) {
                throw new Conflict('This employee already has an open shift. Clock them out first.');
            }
        }

        $finish = $entity->get('finishedAt');

        if ($finish && $start) {
            $seconds = strtotime((string) $finish) - strtotime((string) $start);
            $entity->set('hours', $seconds > 0 ? round($seconds / 3600, 2) : 0);
        } else {
            $entity->set('hours', null);
        }
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        $this->refresh($entity->get('employeeId'));
    }

    public function afterRemove(Entity $entity, array $options = []): void
    {
        $this->refresh($entity->get('employeeId'));
    }

    private function refresh(?string $employeeId): void
    {
        if (!$employeeId) {
            return;
        }

        $employee = $this->entityManager->getEntityById('Employee', $employeeId);

        if (!$employee) {
            return;
        }

        $open = $this->entityManager
            ->getRDBRepository('Shift')
            ->where([
                'employeeId' => $employeeId,
                'finishedAt' => null,
                'deleted' => false,
            ])
            ->order('startedAt', 'DESC')
            ->findOne();

        $employee->set('onShift', (bool) $open);
        $employee->set('shiftStartedAt', $open ? $open->get('startedAt') : null);

        $this->entityManager->saveEntity($employee, ['skipHooks' => true]);
    }
}
