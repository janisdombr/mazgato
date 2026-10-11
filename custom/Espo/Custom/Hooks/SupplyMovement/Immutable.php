<?php

namespace Espo\Custom\Hooks\SupplyMovement;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

/**
 * The stock journal is append-only and written only by Tools/Inventory (`allowLedgerWrite`): through the entity API a
 * row can be read, never created, edited or removed. A wrong count is corrected by a NEW movement (a stocktake).
 */
class Immutable
{
    public static $order = 1;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('stock_journal_server_only');
        }
    }

    public function beforeRemove(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('stock_journal_server_only');
        }
    }
}
