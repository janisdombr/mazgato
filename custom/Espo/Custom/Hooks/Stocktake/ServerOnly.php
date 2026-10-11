<?php

namespace Espo\Custom\Hooks\Stocktake;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

/**
 * A stocktake is created and posted only by Tools/Inventory (`allowLedgerWrite`): through the entity API it can be read, never created, edited or removed.
 */
class ServerOnly
{
    public static $order = 1;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('stocktake_server_only');
        }
    }

    public function beforeRemove(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            throw new Forbidden('stocktake_server_only');
        }
    }
}
