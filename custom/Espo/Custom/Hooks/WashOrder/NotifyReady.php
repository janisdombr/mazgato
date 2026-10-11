<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Notify\Notifier;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * When an order becomes Completed, tell the customer the car is ready — once.
 * `readyNotifiedAt` is stamped BEFORE sending (direct query), so a re-save, a re-open or a
 * concurrent save can never produce a second message. The Notifier never throws; the extra
 * try/catch here is the belt to its braces: an order save must not fail because of a channel.
 */
class NotifyReady
{
    public static $order = 90;

    public function __construct(
        private EntityManager $em,
        private Notifier $notifier,
        private Config $config
    ) {}

    public function afterSave(Entity $entity, array $options = []): void
    {
        // a subscription SALE is not a car being ready - nobody is told "your car is ready"
        if (!empty($options['skipNotify']) || $entity->get('subscriptionSale') || $entity->get('status') !== 'Completed') {
            return;
        }

        if (!$entity->isAttributeChanged('status') || $entity->get('readyNotifiedAt')) {
            return;
        }

        try {
            $now = date('Y-m-d H:i:s');
            // Claim the notification atomically: only the request that flips NULL -> now sends.
            $claimed = $this->em->getQueryExecutor()->execute(
                $this->em->getQueryBuilder()->update()->in('WashOrder')
                    ->set(['readyNotifiedAt' => $now])
                    ->where(['id' => $entity->getId(), 'readyNotifiedAt' => null])
                    ->build()
            )->rowCount();

            if ($claimed < 1) {
                return;
            }

            $entity->set('readyNotifiedAt', $now);
            $entity->setFetched('readyNotifiedAt', $now);
            $this->notifier->notify($entity, 'Ready');
        } catch (\Throwable $e) {
            // swallowed on purpose
        }
    }
}
