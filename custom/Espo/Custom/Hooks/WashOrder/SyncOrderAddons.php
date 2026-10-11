<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Custom\Tools\OrderPrice\Distributor;
use Espo\Custom\Tools\OrderPrice\Recalculator;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Keep the order's priced add-on lines (OrderAddon) in step with its `addons` links.
 *
 * The price on a line belongs to THIS order: it is fixed in the Quick Intake form and may
 * be edited afterwards on the order itself without touching the service catalogue. This
 * hook only fills the gaps — an add-on ticked later on the order page gets a line at the
 * catalogue price (corporate-aware), and a line whose add-on was removed goes away.
 *
 * An existing line is never re-priced: whatever was charged stays charged. The one
 * exception is an EXPLICIT edit of finalPrice on the order form — the operator typed a
 * new total, so the difference against the add-on lines is put on the base line (same as
 * typing a total in the intake form). The lines then still sum to what was asked.
 *
 * After syncing, the Recalculator re-derives finalPrice, the VAT split and the printable
 * lines/totals HTML from the lines — the lines are the single truth about the money.
 */
class SyncOrderAddons
{
    public static $order = 15;

    public function __construct(
        private EntityManager $entityManager,
        private Recalculator $recalculator
    ) {}

    /**
     * The base wash is a line too, so the order page shows ONE priced list (base + add-ons)
     * instead of a service field in one place and prices in another. Line 0 is the base.
     * Its price is never re-priced once set — the amount charged on this order stands.
     */
    private function syncBaseLine(Entity $entity): void
    {
        $em = $this->entityManager;

        $serviceId = $entity->get('serviceId');

        $line = $em->getRDBRepository('OrderAddon')
            ->where(['washOrderId' => $entity->getId(), 'isBase' => true, 'deleted' => false])
            ->findOne();

        if (!$serviceId) {
            if ($line) {
                $em->removeEntity($line);
            }

            return;
        }

        // Base wash swapped for another one — the old line no longer describes the order.
        if ($line && $line->get('serviceId') !== $serviceId) {
            $em->removeEntity($line);
            $line = null;
        }

        if ($line) {
            return;
        }

        $service = $em->getEntityById('WashService', $serviceId);

        if (!$service) {
            return;
        }

        $price = $entity->get('isCorporate')
            ? ($service->get('priceCorporate') ?? $service->get('priceRetail'))
            : $service->get('priceRetail');

        $line = $em->getNewEntity('OrderAddon');

        $line->set([
            'name' => $service->get('name'),
            'price' => $price !== null ? (float) $price : null,
            'serviceId' => $serviceId,
            'washOrderId' => $entity->getId(),
            'isBase' => true,
            'sortOrder' => 0,
        ]);

        $em->saveEntity($line);
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['skipSyncOrderAddons'])) {
            return;
        }

        $em = $this->entityManager;

        $this->syncBaseLine($entity);

        $addons = $em->getRDBRepository('WashOrder')->getRelation($entity, 'addons')->find();

        $linkedIds = [];

        foreach ($addons as $addon) {
            $linkedIds[$addon->getId()] = $addon;
        }

        $lines = $em->getRDBRepository('OrderAddon')
            ->where(['washOrderId' => $entity->getId(), 'deleted' => false])
            ->find();

        $haveIds = [];

        foreach ($lines as $line) {
            // The base line is maintained by syncBaseLine, not by the add-on links.
            if ($line->get('isBase')) {
                continue;
            }

            $serviceId = $line->get('serviceId');

            if ($serviceId && isset($linkedIds[$serviceId])) {
                $haveIds[$serviceId] = true;

                continue;
            }

            // A line with no service (typed by hand on the order) is left alone; only a
            // line whose add-on was unlinked is removed.
            if ($serviceId) {
                $em->removeEntity($line);
            }
        }

        $isCorporate = (bool) $entity->get('isCorporate');

        foreach ($linkedIds as $serviceId => $service) {
            if (isset($haveIds[$serviceId])) {
                continue;
            }

            $price = $isCorporate
                ? ($service->get('priceCorporate') ?? $service->get('priceRetail'))
                : $service->get('priceRetail');

            $line = $em->getNewEntity('OrderAddon');

            $line->set([
                'name' => $service->get('name'),
                'price' => $price !== null ? (float) $price : null,
                'serviceId' => $serviceId,
                'washOrderId' => $entity->getId(),
                // Add-ons sort after the base line (0).
                'sortOrder' => 10,
            ]);

            $em->saveEntity($line);
        }

        $this->applyManualTotal($entity);

        // Even if nothing above changed a line, the print fields may still be stale
        // (payment method or PVN flag edited on the order itself).
        $this->recalculator->recalculate($entity->getId());
    }

    /**
     * The operator typed a total on the order form. The lines must still be the truth, so they are
     * brought to it by Distributor: a higher total puts the difference on the base line, a LOWER one
     * (a discount, down to 0) scales every line in proportion, exact to the cent.
     */
    private function applyManualTotal(Entity $entity): void
    {
        if (!$entity->isAttributeChanged('finalPrice')) {
            return;
        }

        $v = $entity->get('finalPrice');

        if ($v === null || $v === '') {
            return;
        }

        $target = (float) $v;

        $em = $this->entityManager;

        $lines = $em->getRDBRepository('OrderAddon')
            ->where(['washOrderId' => $entity->getId(), 'deleted' => false])
            ->order('sortOrder')
            ->find();

        $byId = [];
        $prices = [];
        $baseKey = null;

        foreach ($lines as $line) {
            $byId[$line->getId()] = $line;
            $prices[$line->getId()] = (float) $line->get('price');

            if ($line->get('isBase')) {
                $baseKey = $line->getId();
            }
        }

        if (!$prices) {
            return;
        }

        $new = Distributor::fit($prices, $baseKey, $target);

        foreach ($new as $id => $price) {
            if (abs($price - $prices[$id]) < 0.005) {
                continue;
            }

            $byId[$id]->set('price', $price);
            $em->saveEntity($byId[$id]);
        }
    }
}
