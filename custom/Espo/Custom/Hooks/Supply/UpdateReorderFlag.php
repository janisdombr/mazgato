<?php

namespace Espo\Custom\Hooks\Supply;

use Espo\ORM\Entity;

/**
 * Flag a supply for reorder when its stock falls to or below the reorder level.
 * Used to plan purchases (filter the Supply list by "Needs reorder").
 */
class UpdateReorderFlag
{
    public static $order = 9;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $reorderLevel = (float) $entity->get('reorderLevel');
        $stock = (float) $entity->get('stockQuantity');

        $entity->set('needsReorder', $reorderLevel > 0 && $stock <= $reorderLevel);
    }
}
