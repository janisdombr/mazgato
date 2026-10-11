<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\ORM\Entity;

/**
 * Split the (VAT-inclusive) final price into net + VAT for the invoice.
 * Latvia standard VAT rate is 21%.
 */
class CalculateAmounts
{
    public static $order = 9;

    private const VAT_RATE = 0.21;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $gross = (float) $entity->get('finalPrice');
        $currency = $entity->get('finalPriceCurrency') ?: 'EUR';

        $net = $gross / (1 + self::VAT_RATE);

        $entity->set('amountNet', round($net, 2));
        $entity->set('amountNetCurrency', $currency);

        $entity->set('vatAmount', round($gross - $net, 2));
        $entity->set('vatAmountCurrency', $currency);
    }
}
