<?php

namespace Espo\Custom\Tools\Data;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * "Set the real plate": rename a car that has a temporary number, keeping its history (orders point at the Vehicle id,
 * so nothing moves). A real plate that already belongs to another car -> 409 `plate_exists`; with `merge` this car's
 * orders / events / drivers' links move to the existing car and this one is deleted.
 */
class PlateRename
{
    public function __construct(private EntityManager $em, private Acl $acl)
    {}

    public function run(Entity $vehicle, string $rawPlate, bool $merge): \stdClass
    {
        $plate = Parties::normalizePlate($rawPlate);

        if ($plate === '' || strlen($plate) > 12) {
            throw new BadRequest('plate_required');
        }

        if (!$vehicle->get('plateTemporary')) {
            throw new BadRequest('plate_not_temporary');
        }

        if (preg_match('/^' . Parties::PREFIX . '\d{9,}$/', $plate)) {
            throw new BadRequest('plate_is_temporary');
        }

        $other = $this->em->getRDBRepository('Vehicle')
            ->where(['name' => $plate, 'deleted' => false, 'id!=' => $vehicle->getId()])->findOne();

        if ($other) {
            if (!$merge) {
                throw Conflict::createWithBody('plate_exists', (string) json_encode([
                    'error' => 'plate_exists', 'existingVehicleId' => $other->getId(), 'canMerge' => true,
                    'existingCustomerId' => $other->get('customerId'),
                ]));
            }

            if (!$this->acl->checkEntity($other, 'edit')) {
                throw new Forbidden('No edit access to the existing car.');
            }

            return $this->merge($vehicle, $other);
        }

        $vehicle->set(['name' => $plate, 'plateTemporary' => false]);
        $this->em->saveEntity($vehicle);

        return (object) ['ok' => true, 'merged' => false, 'vehicleId' => $vehicle->getId(), 'plate' => $plate];
    }

    private function merge(Entity $temp, Entity $target): \stdClass
    {
        $moved = [];
        $q = $this->em->getQueryExecutor();
        $b = fn() => $this->em->getQueryBuilder();

        foreach (['WashOrder' => 'vehicleId', 'PlateEvent' => 'vehicleId'] as $entityType => $col) {
            if (!$this->em->getDefs()->getEntity($entityType)->hasField('vehicle')) {
                continue;
            }

            $n = $this->em->getRDBRepository($entityType)->where([$col => $temp->getId(), 'deleted' => false])->count();
            $q->execute($b()->update()->in($entityType)->set([$col => $target->getId()])->where([$col => $temp->getId()])->build());
            $moved[$entityType] = $n;
        }

        // The existing car may have had no owner: it takes the owner of the car being merged.
        if (!$target->get('customerId') && $temp->get('customerId')) {
            $target->set('customerId', $temp->get('customerId'));
            $this->em->saveEntity($target);
        }

        $this->em->removeEntity($temp);

        return (object) ['ok' => true, 'merged' => true, 'vehicleId' => $target->getId(), 'plate' => $target->get('name'), 'moved' => $moved];
    }
}
