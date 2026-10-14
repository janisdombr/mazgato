<?php

namespace Espo\Custom\Hooks\AccountingSync;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

/**
 * The accounting ledger is written only by Tools/Accounting (`allowLedgerWrite`): through the entity API a row can be read,
 * never created, edited or removed - a person cannot mark an order "Sent" without it having been sent, or erase a failure.
 * "Send again" is the controller action AccountingSync/resend, which goes through the service.
 */
class Immutable
{
    public static $order = 1;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('accounting_ledger_server_only');
        }
    }

    public function beforeRemove(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('accounting_ledger_server_only');
        }
    }
}
