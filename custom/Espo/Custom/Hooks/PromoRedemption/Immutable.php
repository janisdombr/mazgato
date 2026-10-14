<?php

namespace Espo\Custom\Hooks\PromoRedemption;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

/**
 * The promo-code ledger is written only by Tools/Loyalty (`allowLedgerWrite`; status changes are direct, atomic queries).
 * Through the entity API a row can be read, never created, edited or deleted.
 */
class Immutable
{
    public static $order = 1;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('promo_ledger_server_only');
        }
    }

    public function beforeRemove(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('promo_ledger_server_only');
        }
    }
}
