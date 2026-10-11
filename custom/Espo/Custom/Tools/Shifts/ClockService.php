<?php

namespace Espo\Custom\Tools\Shifts;

use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;

/**
 * Clock an employee in or out. The one implementation behind `CarWash/action/clock`
 * (the Shifts dashlet) and `Kiosk/action/clock` (the tablet) — the Shift hook
 * (`Hooks/Shift/SyncEmployee`) still owns the rules: hours, the employee mirror and
 * "one open shift per employee".
 */
class ClockService
{
    public function __construct(private EntityManager $entityManager)
    {}

    /**
     * Toggle: close the open shift, otherwise open one.
     *
     * @throws NotFound
     */
    public function toggle(string $employeeId): array
    {
        return $this->openShift($employeeId) ? $this->clockOut($employeeId) : $this->clockIn($employeeId);
    }

    /**
     * Idempotent: an already open shift is returned as is (`changed` false), never doubled.
     *
     * @throws NotFound
     */
    public function clockIn(string $employeeId): array
    {
        $employee = $this->employee($employeeId);
        $open = $this->openShift($employeeId);

        if ($open) {
            return [
                'onShift' => true,
                'changed' => false,
                'shiftId' => $open->getId(),
                'startedAt' => $open->get('startedAt'),
                'employeeName' => $employee->get('name'),
            ];
        }

        $shift = $this->entityManager->createEntity('Shift', [
            'employeeId' => $employeeId,
            'startedAt' => date('Y-m-d H:i:s'),
        ]);

        return [
            'onShift' => true,
            'changed' => true,
            'shiftId' => $shift->getId(),
            'startedAt' => $shift->get('startedAt'),
            'employeeName' => $employee->get('name'),
        ];
    }

    /**
     * Idempotent: no open shift means there is nothing to close (`changed` false).
     *
     * @throws NotFound
     */
    public function clockOut(string $employeeId): array
    {
        $employee = $this->employee($employeeId);
        $open = $this->openShift($employeeId);

        if (!$open) {
            return [
                'onShift' => false,
                'changed' => false,
                'employeeName' => $employee->get('name'),
            ];
        }

        $open->set('finishedAt', date('Y-m-d H:i:s'));
        $this->entityManager->saveEntity($open);

        return [
            'onShift' => false,
            'changed' => true,
            'shiftId' => $open->getId(),
            'startedAt' => $open->get('startedAt'),
            'finishedAt' => $open->get('finishedAt'),
            'hours' => $open->get('hours'),
            'employeeName' => $employee->get('name'),
        ];
    }

    private function employee(string $employeeId): \Espo\ORM\Entity
    {
        $employee = $this->entityManager->getEntityById('Employee', $employeeId);

        if (!$employee) {
            throw new NotFound('Employee not found.');
        }

        return $employee;
    }

    private function openShift(string $employeeId): ?\Espo\ORM\Entity
    {
        return $this->entityManager
            ->getRDBRepository('Shift')
            ->where([
                'employeeId' => $employeeId,
                'finishedAt' => null,
                'deleted' => false,
            ])
            ->order('startedAt', 'DESC')
            ->findOne();
    }
}
