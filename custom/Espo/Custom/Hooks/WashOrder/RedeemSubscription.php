<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Custom\Tools\Subscriptions\Subscriptions;
use Espo\ORM\Entity;

/**
 * Completed + tied to a subscription -> spend one wash, once (idempotent: a unique ledger row per order).
 * Cancelled / re-opened after being spent -> the wash comes back. Runs after SyncOrderAddons (15) and
 * NotifyReady (90) so the lines exist when they are brought to 0 EUR.
 */
class RedeemSubscription
{
    public static $order = 95;

    public function __construct(private Subscriptions $subscriptions)
    {}

    public function afterSave(Entity $entity, array $options = []): void
    {
        if ($entity->get('status') === 'Completed') {
            $this->subscriptions->redeem($entity);
        } elseif ($entity->isAttributeChanged('status')) {
            $this->subscriptions->release($entity);
        }
    }
}
