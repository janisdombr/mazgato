<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Build a human-readable summary of the supplies the order will consume
 * (base wash + add-ons, per the ServiceSupply bill-of-materials), e.g.
 * "Šampūns 2 L, Mikrošķiedras salvetes 3 pcs". Shown read-only on the order.
 */
class CalculateSupplies
{
    public static $order = 11;

    public function __construct(private EntityManager $entityManager)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $serviceIds = [];

        if ($entity->get('serviceId')) {
            $serviceIds[] = $entity->get('serviceId');
        }

        foreach ($entity->getLinkMultipleIdList('addons') as $addonId) {
            $serviceIds[] = $addonId;
        }

        $totals = [];

        foreach ($serviceIds as $serviceId) {
            $lines = $this->entityManager
                ->getRDBRepository('ServiceSupply')
                ->where(['serviceId' => $serviceId])
                ->find();

            foreach ($lines as $line) {
                $supplyId = $line->get('supplyId');
                $qty = (float) $line->get('quantity');

                if (!$supplyId || $qty <= 0) {
                    continue;
                }

                if (!isset($totals[$supplyId])) {
                    $supply = $this->entityManager->getEntityById('Supply', $supplyId);

                    if (!$supply) {
                        continue;
                    }

                    $totals[$supplyId] = [
                        'name' => $supply->get('name'),
                        'unit' => $supply->get('unit'),
                        'qty' => 0.0,
                    ];
                }

                $totals[$supplyId]['qty'] += $qty;
            }
        }

        $parts = [];

        foreach ($totals as $t) {
            $parts[] = $t['name'] . ' ' . round($t['qty'], 2) . ' ' . $t['unit'];
        }

        $entity->set('suppliesSummary', $parts ? implode(', ', $parts) : null);
    }
}
