<?php

namespace Espo\Custom\Hooks\SubscriptionUse;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

/**
 * The subscription ledger is written only by Tools/Subscriptions (`allowLedgerWrite`); through the entity API a row
 * can be read, never created or edited - nobody can hand-spend or hand-refund a wash.
 */
class Immutable
{
    public static $order = 1;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('subscription_ledger_server_only');
        }
    }

    public function beforeRemove(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('subscription_ledger_server_only');
        }
    }
}
