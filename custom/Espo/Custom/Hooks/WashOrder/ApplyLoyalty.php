<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Loyalty\Loyalty;
use Espo\Custom\Tools\Loyalty\Promo;
use Espo\Custom\Tools\Loyalty\Referral;
use Espo\ORM\Entity;

/**
 * Loyalty discounts (promo code / every N-th wash free) on the order - see Tools/Loyalty/Loyalty.
 * Runs after SyncOrderAddons (15), so the priced lines exist; before NotifyReady (90) and RedeemSubscription (95).
 * `createOrder` saves the order BEFORE its lines carry the charged prices, so it passes `skipApplyLoyalty` and calls the
 * same code itself at the end. A failure here is logged, never thrown: a broken discount must not stop a car being taken in.
 */
class ApplyLoyalty
{
    public static $order = 20;

    public function __construct(private Loyalty $loyalty, private Log $log)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if ($entity->has('promoCode')) {
            $entity->set('promoCode', Promo::normalize((string) $entity->get('promoCode')) ?: null);
        }

        if ($entity->has('referralCode')) {
            $entity->set('referralCode', Referral::normalize((string) $entity->get('referralCode')) ?: null);
        }
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['skipApplyLoyalty'])) {
            return;
        }

        try {
            $this->loyalty->onSave($entity);
        } catch (\Throwable $e) {
            $this->log->error('Loyalty hook failed for order ' . $entity->getId() . ': ' . $e->getMessage());
        }
    }

    public function afterRemove(Entity $entity, array $options = []): void
    {
        try {
            if ($this->loyalty->enabled()) {
                $this->loyalty->onRemove($entity);
            }
        } catch (\Throwable $e) {
            $this->log->error('Loyalty release failed for order ' . $entity->getId() . ': ' . $e->getMessage());
        }
    }
}
