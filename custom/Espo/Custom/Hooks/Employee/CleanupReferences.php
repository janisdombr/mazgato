<?php

namespace Espo\Custom\Hooks\Employee;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * When an employee is removed, clear their references from orders (washer / dryer /
 * interior) so orders never point at a non-existent employee (which would show a
 * raw id in lists and the payroll report).
 */
class CleanupReferences
{
    public static $order = 9;

    public function __construct(private EntityManager $entityManager)
    {}

    public function afterRemove(Entity $entity, array $options = []): void
    {
        $id = $entity->getId();

        foreach (['washerId', 'dryerId', 'salonCleanerId'] as $field) {
            $orders = $this->entityManager
                ->getRDBRepository('WashOrder')
                ->where([$field => $id])
                ->find();

            foreach ($orders as $order) {
                $order->set($field, null);
                $this->entityManager->saveEntity($order, ['skipHooks' => true, 'silent' => true]);
            }
        }
    }
}
