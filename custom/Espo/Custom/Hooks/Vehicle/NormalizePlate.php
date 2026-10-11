<?php

namespace Espo\Custom\Hooks\Vehicle;

use Espo\ORM\Entity;

/**
 * Normalise the plate number: uppercase, no dashes, spaces or other separators.
 * "LV-1234" / "lv 1234" -> "LV1234".
 */
class NormalizePlate
{
    public static $order = 5;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $name = (string) $entity->get('name');

        if ($name === '') {
            return;
        }

        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));

        if ($normalized !== $name) {
            $entity->set('name', $normalized);
        }
    }
}
