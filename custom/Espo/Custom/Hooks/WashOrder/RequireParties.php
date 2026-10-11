<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Data\Parties;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The server-side truth of docs/DATA_RULES.md, whatever the path (entity API, createOrder, reserve, booking, ANPR,
 * e-mail, assistant draft): an order ALWAYS has a customer (the payer, with a name) and a car.
 *
 *  - no customer -> 400 `customer_name_required` (a car's own customer is used when the car has one);
 *  - no car      -> a temporary number BN-<yymmdd>-<NNN> is minted and the car created for that customer;
 *  - an update may not EMPTY the car or the customer (400 `vehicle_required` / `customer_required`);
 *  - exception: the sale of a subscription (`subscriptionSale`) is money without a car - customer still required.
 *
 * A legacy order that already lacks them is not blocked on unrelated edits; `bin/command repair-orders` fixes those.
 */
class RequireParties
{
    public static $order = 1;

    public function __construct(private EntityManager $em, private Parties $parties)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['skipPartyCheck'])) {
            return;
        }

        $isNew = $entity->isNew();
        $sale = (bool) $entity->get('subscriptionSale');

        if (!$isNew) {
            if ($entity->isAttributeChanged('customerId') && !$entity->get('customerId')) {
                throw new BadRequest('customer_required');
            }

            if (!$sale && $entity->isAttributeChanged('vehicleId') && !$entity->get('vehicleId')) {
                throw new BadRequest('vehicle_required');
            }

            return;
        }

        $customerId = (string) $entity->get('customerId');
        $vehicleId = (string) $entity->get('vehicleId');

        if ($customerId === '' && $vehicleId !== '' && !$sale) {
            $vehicle = $this->em->getEntityById('Vehicle', $vehicleId);
            $customerId = $vehicle ? (string) $vehicle->get('customerId') : '';

            if ($customerId !== '') {
                $entity->set('customerId', $customerId);
            }
        }

        $customer = $customerId !== '' ? $this->em->getEntityById('Customer', $customerId) : null;

        if (!$customer || trim((string) $customer->get('name')) === '') {
            throw new BadRequest('customer_name_required');
        }

        if ($sale) {
            return;
        }

        if ($vehicleId === '') {
            $vehicle = $this->parties->createTemporaryVehicle($customerId);
            $entity->set('vehicleId', $vehicle->getId());
        } elseif (!$this->em->getEntityById('Vehicle', $vehicleId)) {
            throw new BadRequest('vehicle_required');
        }
    }
}
