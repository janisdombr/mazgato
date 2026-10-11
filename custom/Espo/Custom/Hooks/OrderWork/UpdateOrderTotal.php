<?php

namespace Espo\Custom\Hooks\OrderWork;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Keep WashOrder.worksTotal = sum of its order-work amounts (the wage cost of the
 * order). Saved with skipHooks so it cannot recurse back into the order's own hooks.
 */
class UpdateOrderTotal
{
    public static $order = 20;

    public function __construct(private EntityManager $entityManager)
    {}

    public function afterSave(Entity $entity, array $options = []): void
    {
        $this->recalculate($entity->get('washOrderId'));
    }

    public function afterRemove(Entity $entity, array $options = []): void
    {
        $this->recalculate($entity->get('washOrderId'));
    }

    private function recalculate(?string $orderId): void
    {
        if (!$orderId) {
            return;
        }

        $order = $this->entityManager->getEntityById('WashOrder', $orderId);

        if (!$order) {
            return;
        }

        $lines = $this->entityManager
            ->getRDBRepository('OrderWork')
            ->where(['washOrderId' => $orderId])
            ->find();

        $total = 0.0;

        foreach ($lines as $line) {
            $total += (float) $line->get('amount');
        }

        $order->set('worksTotal', round($total, 2));
        $order->set('worksTotalCurrency', 'EUR');

        $this->entityManager->saveEntity($order, ['skipHooks' => true]);
    }
}
