<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\ORM\Entity;
use Espo\Custom\Tools\Inventory\Stock;
use Espo\ORM\EntityManager;

/**
 * When an order becomes Completed, deduct the supplies consumed by its base wash
 * and add-ons (per the ServiceSupply bill-of-materials) from stock. Runs once —
 * guarded by the `suppliesDeducted` flag. Each deduction is a `Consumption` movement in the stock journal.
 */
class DeductSupplies
{
    public static $order = 12;

    public function __construct(private EntityManager $entityManager, private Stock $stock)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if ($entity->get('suppliesDeducted')) {
            return;
        }

        if ($entity->get('status') !== 'Completed') {
            return;
        }

        // Only when the order is being created completed or transitions to completed.
        if (!$entity->isNew() && !$entity->isAttributeChanged('status')) {
            return;
        }

        $serviceIds = [];

        if ($entity->get('serviceId')) {
            $serviceIds[] = $entity->get('serviceId');
        }

        foreach ($entity->getLinkMultipleIdList('addons') as $addonId) {
            $serviceIds[] = $addonId;
        }

        foreach ($serviceIds as $serviceId) {
            $lines = $this->entityManager
                ->getRDBRepository('ServiceSupply')
                ->where(['serviceId' => $serviceId])
                ->find();

            foreach ($lines as $line) {
                $supplyId = $line->get('supplyId');
                $quantity = (float) $line->get('quantity');

                if (!$supplyId || $quantity <= 0) {
                    continue;
                }

                // One atomic UPDATE + a journal row (Tools/Inventory/Stock), not a read-modify-write of a stale entity.
                try {
                    $this->stock->consume($supplyId, $quantity, $entity->getId() ?: null, (string) ($entity->get('name') ?: ''));
                } catch (\Espo\Core\Exceptions\BadRequest $e) {
                    // the supply was deleted: nothing to deduct (as before)
                }
            }
        }

        $entity->set('suppliesDeducted', true);
    }
}
