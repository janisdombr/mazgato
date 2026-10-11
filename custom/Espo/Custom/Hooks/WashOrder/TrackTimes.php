<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\ORM\Entity;

/**
 * Stamp when the work actually started and finished:
 *  - `startedAt`  when the order enters "In Progress" (that is also the default status
 *    on create, so a walk-in order is stamped the moment it is taken in);
 *  - `finishedAt` when it is Completed.
 *
 * Each stamp is written once and never moved: re-opening a completed order and
 * completing it again keeps the original finish time. Clear the field to re-stamp.
 */
class TrackTimes
{
    public static $order = 6;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (!$entity->isNew() && !$entity->isAttributeChanged('status')) {
            return;
        }

        $status = $entity->get('status');
        $now = date('Y-m-d H:i:s');

        if ($status === 'In Progress' && !$entity->get('startedAt')) {
            $entity->set('startedAt', $now);
        }

        if ($status === 'Completed') {
            // An order completed straight away (never passed through In Progress) still
            // needs a start time, otherwise the duration is unknowable.
            if (!$entity->get('startedAt')) {
                $entity->set('startedAt', $now);
            }

            if (!$entity->get('finishedAt')) {
                $entity->set('finishedAt', $now);
            }
        }
    }
}
