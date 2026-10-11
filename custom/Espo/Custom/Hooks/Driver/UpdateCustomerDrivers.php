<?php

namespace Espo\Custom\Hooks\Driver;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;

/**
 * Keep Customer.driverNames — the customer's drivers as one comma-separated string,
 * newest first — in step with the Driver records, so drivers can be a COLUMN on the
 * customer list. Same denormalisation pattern as Vehicle/UpdateCustomerPlates.
 */
class UpdateCustomerDrivers
{
    public static $order = 12;

    public function __construct(private EntityManager $entityManager)
    {}

    public function afterSave(Entity $entity, array $options = []): void
    {
        $this->refresh($entity->get('customerId'));

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

        $drivers = $em->getRDBRepository('Driver')
            ->where(['customerId' => $customerId, 'deleted' => false])
            ->order('createdAt', 'DESC')
            ->find();

        $names = [];

        foreach ($drivers as $driver) {
            $names[] = $driver->get('name');
        }

        $update = $em->getQueryBuilder()
            ->update()
            ->in('Customer')
            ->set(['driverNames' => mb_substr(implode(', ', array_filter($names)), 0, 255)])
            ->where(Cond::equal(Cond::column('id'), $customerId))
            ->build();

        $em->getQueryExecutor()->execute($update);
    }
}
