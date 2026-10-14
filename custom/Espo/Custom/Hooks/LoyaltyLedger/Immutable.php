<?php

namespace Espo\Custom\Hooks\LoyaltyLedger;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

/**
 * The points ledger is written only by Tools/Loyalty/Points::move() (`allowLedgerWrite`; lot `remaining` is changed by direct, atomic queries).
 * Through the entity API a row can be read, never created, edited or deleted.
 */
class Immutable
{
    public static $order = 1;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('loyalty_ledger_server_only');
        }
    }

    public function beforeRemove(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('loyalty_ledger_server_only');
        }
    }
}
