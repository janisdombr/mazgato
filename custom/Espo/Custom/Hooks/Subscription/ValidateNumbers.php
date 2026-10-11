<?php

namespace Espo\Custom\Hooks\Subscription;

use Espo\Custom\Tools\Validation\Numbers;
use Espo\ORM\Entity;

/** Money / quantities on a Subscription must be finite and not negative (400 naming the field). */
class ValidateNumbers
{
    public static $order = 1;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        Numbers::nonNegative($entity, ['price', 'washesTotal', 'washesRemaining']);
    }
}
