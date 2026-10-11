<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Suggest finalPrice = base wash + selected add-ons (corporate price when the
 * customer is corporate). Runs after SetDefaults (isCorporate) and before
 * CalculateAmounts (VAT). Only fills the price when it is empty, so a manually
 * entered/edited amount is always kept. Clear the price to recompute.
 */
class CalculatePrice
{
    public static $order = 8;

    public function __construct(private EntityManager $entityManager)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        // Keep a manually entered/edited price.
        if ((float) $entity->get('finalPrice') > 0) {
            return;
        }

        // An EXPLICIT edit down to 0 on an existing order is a 100 % discount, not "work it out"
        // (clearing the field sends null, which still recomputes). A new order keeps 0 = "work it out":
        // createOrder applies its own explicit total to the lines afterwards.
        if (
            !$entity->isNew()
            && $entity->isAttributeChanged('finalPrice')
            && $entity->get('finalPrice') !== null
            && $entity->get('finalPrice') !== ''
            && (float) $entity->getFetched('finalPrice') > 0
        ) {
            return;
        }

        $serviceId = $entity->get('serviceId');

        if (!$serviceId) {
            return;
        }

        $corporate = (bool) $entity->get('isCorporate');
        $currency = $entity->get('finalPriceCurrency') ?: 'EUR';

        $total = 0.0;

        $service = $this->entityManager->getEntityById('WashService', $serviceId);

        if ($service) {
            $total += $this->priceOf($service, $corporate);
        }

        foreach ($entity->getLinkMultipleIdList('addons') as $addonId) {
            $addon = $this->entityManager->getEntityById('WashService', $addonId);

            if ($addon) {
                $total += $this->priceOf($addon, $corporate);
            }
        }

        $entity->set('finalPrice', round($total, 2));
        $entity->set('finalPriceCurrency', $currency);
    }

    private function priceOf(Entity $service, bool $corporate): float
    {
        $corp = $service->get('priceCorporate');

        if ($corporate && $corp !== null && (float) $corp > 0) {
            return (float) $corp;
        }

        return (float) $service->get('priceRetail');
    }
}
