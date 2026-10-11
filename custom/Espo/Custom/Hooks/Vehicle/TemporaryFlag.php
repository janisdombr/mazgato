<?php

namespace Espo\Custom\Hooks\Vehicle;

use Espo\ORM\Entity;

/**
 * `plateTemporary` falls off by itself the moment the plate is edited into something that is not a temporary
 * number (BN<yymmdd><NNN>) - e.g. a plain rename in the edit form. The proper way (with merge on a clash) is
 * CarWash/action/setRealPlate. The flag is read-only over the API, so it cannot be forged.
 */
class TemporaryFlag
{
    public static $order = 6; // after NormalizePlate (5)

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (
            !$entity->isNew()
            && $entity->get('plateTemporary')
            && $entity->isAttributeChanged('name')
            && !preg_match('/^BN\d{9,}$/', (string) $entity->get('name'))
        ) {
            $entity->set('plateTemporary', false);
        }
    }
}
