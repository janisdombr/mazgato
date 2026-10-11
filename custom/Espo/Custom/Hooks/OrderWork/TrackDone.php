<?php

namespace Espo\Custom\Hooks\OrderWork;

use Espo\ORM\Entity;

/**
 * `startedAt` / `doneAt` on a work line are written once and never moved by a re-save —
 * the same rule as `WashOrder.startedAt/finishedAt` (TrackTimes). A second "done" from a
 * double tap or a replayed offline action cannot push the time forward; to re-stamp, clear
 * the field first. Clearing `doneAt` also clears who and which device closed it, so a
 * line never says "done by Anna" while being open.
 */
class TrackDone
{
    public static $order = 7;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if ($entity->isNew()) {
            return;
        }

        foreach (['startedAt', 'doneAt'] as $field) {
            if (!$entity->isAttributeChanged($field)) {
                continue;
            }

            $old = $entity->getFetched($field);
            $new = $entity->get($field);

            if ($old && $new && $old !== $new) {
                $entity->set($field, $old);
            }
        }

        if ($entity->isAttributeChanged('doneAt') && !$entity->get('doneAt')) {
            $entity->set(['doneById' => null, 'doneDeviceId' => null]);
        }
    }
}
