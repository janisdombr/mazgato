<?php

namespace Espo\Custom\Hooks\Subscription;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

/**
 * Subscription integrity (beforeSave):
 *  - the balance (`washesRemaining`), the sale link and the ledger are owned by the server: an API/UI edit that
 *    touches them is refused (`allowLedgerWrite` is passed only by Tools/Subscriptions);
 *  - Pack needs washesTotal >= 1 (remaining starts there); Unlimited needs validFrom..validTo;
 *  - the period must not be reversed.
 */
class Rules
{
    public static $order = 5;

    private const SERVER_FIELDS = ['washesRemaining', 'saleOrderId'];

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $server = !empty($options['allowLedgerWrite']);

        if (!$server) {
            if ($entity->isNew()) {
                // A hand-created subscription starts full; nobody may type a different balance.
                foreach (self::SERVER_FIELDS as $f) {
                    if ($entity->get($f) !== null && $f === 'saleOrderId') {
                        throw new Forbidden('subscription_server_field');
                    }
                }
            } else {
                foreach (self::SERVER_FIELDS as $f) {
                    if ($entity->isAttributeChanged($f)) {
                        throw new Forbidden('subscription_server_field');
                    }
                }
            }
        }

        $kind = $entity->get('kind') ?: 'Pack';
        $entity->set('kind', $kind);

        if ($kind === 'Pack') {
            if ((int) $entity->get('washesTotal') < 1) {
                throw new BadRequest('washes_required');
            }

            if ($entity->isNew() && !$server) {
                $entity->set('washesRemaining', (int) $entity->get('washesTotal'));
            }
        } else {
            if (!$entity->get('validFrom') || !$entity->get('validTo')) {
                throw new BadRequest('valid_to_required');
            }

            $entity->set('washesTotal', null);
            $entity->set('washesRemaining', null);
        }

        if ($entity->get('validFrom') && $entity->get('validTo') && $entity->get('validTo') < $entity->get('validFrom')) {
            throw new BadRequest('bad_period');
        }

        if ($entity->isNew()) {
            if (!$entity->get('status')) {
                $entity->set('status', 'Active');
            }

            if (!$entity->get('name')) {
                $entity->set('name', 'ABON-' . date('ymd-His'));
            }
        }
    }
}
