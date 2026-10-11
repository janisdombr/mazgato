<?php

namespace Espo\Custom\Hooks\Vehicle;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;

/**
 * Keep Customer.vehiclePlates — the customer's plates as one comma-separated string,
 * newest first — in step with the Vehicle records. A denormalised column, so the plate
 * can be a COLUMN on the customer list (a linkMultiple cannot).
 *
 * Written with a direct query: a plate change must not bump the customer's modifiedAt
 * or run customer hooks.
 */
class UpdateCustomerPlates
{
    public static $order = 12;

    public function __construct(private EntityManager $entityManager)
    {}

    public function afterSave(Entity $entity, array $options = []): void
    {
        $this->refresh($entity->get('customerId'));

        // Re-assigned to another customer — the previous owner's list shrinks too.
        $previous = $entity->getFetched('customerId');

        if ($previous && $previous !== $entity->get('customerId')) {
            $this->refresh($previous);
        }
    }

    public function afterRemove(Entity $entity, array $options = []): void
    {
        $this->refresh($entity->get('customerId'));
    }

    private function refresh(?string $customerId): void
    {
        if (!$customerId) {
            return;
        }

        $em = $this->entityManager;

        $vehicles = $em->getRDBRepository('Vehicle')
            ->where(['customerId' => $customerId, 'deleted' => false])
            ->order('createdAt', 'DESC')
            ->find();

        $plates = [];

        foreach ($vehicles as $vehicle) {
            $plates[] = $vehicle->get('name');
        }

        $update = $em->getQueryBuilder()
            ->update()
            ->in('Customer')
            ->set(['vehiclePlates' => mb_substr(implode(', ', array_filter($plates)), 0, 255)])
            ->where(Cond::equal(Cond::column('id'), $customerId))
            ->build();

        $em->getQueryExecutor()->execute($update);
    }
}
