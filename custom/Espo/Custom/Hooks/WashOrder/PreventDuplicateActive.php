<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Core\Exceptions\Conflict;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * One car can only be on the ramp once: refuse a second order for the same vehicle
 * while another one is still "In Progress".
 *
 * Only checked when this order itself becomes In Progress (on create, or on the status
 * transition) — editing an already-running order, or having several Completed/Reserved
 * orders for the same car, is fine.
 */
class PreventDuplicateActive
{
    public static $order = 5;

    public function __construct(private EntityManager $entityManager)
    {}

    /**
     * @throws Conflict
     */
    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['skipDuplicateCheck'])) {
            return;
        }

        if ($entity->get('status') !== 'In Progress') {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('status')) {
            return;
        }

        $vehicleId = $entity->get('vehicleId');

        if (!$vehicleId) {
            return;
        }

        $builder = $this->entityManager
            ->getRDBRepository('WashOrder')
            ->where([
                'vehicleId' => $vehicleId,
                'status' => 'In Progress',
                'deleted' => false,
            ]);

        if ($entity->getId()) {
            $builder = $builder->where(['id!=' => $entity->getId()]);
        }

        $existing = $builder->findOne();

        if (!$existing) {
            return;
        }

        // vehiclePlate is a foreign field and is not loaded on a new entity — read the
        // plate from the vehicle itself.
        $vehicle = $this->entityManager->getEntityById('Vehicle', $vehicleId);
        $plate = $vehicle ? $vehicle->get('name') : $vehicleId;

        throw new Conflict(
            "Vehicle {$plate} already has an order in progress ({$existing->get('name')}). " .
            "Complete or cancel it before starting a new one."
        );
    }
}
