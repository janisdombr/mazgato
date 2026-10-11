<?php

namespace Espo\Custom\Hooks\Supply;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Every change of the stock is in the journal. Receipts, stocktakes and orders write their own movements through
 * Tools/Inventory (SQL, no entity save, so this hook never sees them); a hand edit of "In stock" on the supply form
 * (or an import / the API) is recorded here as a `Manual` movement - who, when, how much - so the journal and the
 * quantity cannot drift apart. The proper ways to change stock are the Receipt and Stocktake dialogs.
 */
class LedgerManualEdit
{
    public static $order = 20;

    public function __construct(private EntityManager $em)
    {}

    public function afterSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['allowLedgerWrite']) || !$entity->isAttributeChanged('stockQuantity')) {
            return;
        }

        $after = (float) $entity->get('stockQuantity');
        $before = $entity->isNew() ? 0.0 : (float) $entity->getFetched('stockQuantity');
        $delta = round($after - $before, 4);

        if ($delta == 0.0) {
            return;
        }

        $m = $this->em->getNewEntity('SupplyMovement');
        $m->set([
            'name' => 'Manual ' . ($delta > 0 ? '+' : '') . rtrim(rtrim(number_format($delta, 4, '.', ''), '0'), '.'),
            'kind' => 'Manual',
            'supplyId' => $entity->getId(),
            'delta' => $delta,
            'quantityBefore' => $before,
            'quantityAfter' => $after,
            'unitCost' => (float) $entity->get('unitCost'),
            'unitCostCurrency' => $entity->get('unitCostCurrency'),
            'reason' => $entity->isNew() ? 'Opening balance' : 'Edited on the supply form',
            'occurredAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->em->saveEntity($m, ['allowLedgerWrite' => true]);
    }
}
