<?php

namespace Espo\Custom\Hooks\OrderAddon;

use Espo\Custom\Tools\OrderPrice\Recalculator;
use Espo\ORM\Entity;

/**
 * A line changed → the order's money changed. Wherever the line was touched — the lines
 * panel, the intake endpoint overwriting a catalogue price with the charged one, an
 * add-on synced on/off the order — the order's finalPrice, VAT split and printable
 * lines/totals HTML are re-derived from the lines. The Recalculator writes with a direct
 * query, so no WashOrder hooks re-enter and no loop is possible.
 */
class UpdateOrderPrice
{
    public static $order = 10;

    public function __construct(private Recalculator $recalculator)
    {}

    public function afterSave(Entity $entity, array $options = []): void
    {
        $this->touch($entity);
    }

    public function afterRemove(Entity $entity, array $options = []): void
    {
        $this->touch($entity);
    }

    private function touch(Entity $entity): void
    {
        $orderId = $entity->get('washOrderId');

        if ($orderId) {
            $this->recalculator->recalculate($orderId);
        }
    }
}
