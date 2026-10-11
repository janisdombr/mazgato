<?php

namespace Espo\Custom\Hooks\MediaAccess;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

/**
 * The access journal is append-only: a row can be created, read and (by an admin)
 * retired, never edited. The only server-side update is the replay counter
 * (`allowAuditUpdate`), which does not alter what happened.
 */
class Immutable
{
    public static $order = 1;

    /**
     * @throws Forbidden
     */
    public function beforeSave(Entity $entity, array $options = []): void
    {
        if ($entity->isNew() || !empty($options['allowAuditUpdate'])) {
            return;
        }

        throw new Forbidden('Access journal rows cannot be edited.');
    }
}
