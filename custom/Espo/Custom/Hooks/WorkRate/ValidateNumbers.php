<?php

namespace Espo\Custom\Hooks\WorkRate;

use Espo\Custom\Tools\Validation\Numbers;
use Espo\ORM\Entity;

/** Money / quantities on a WorkRate must be finite and not negative (400 naming the field). */
class ValidateNumbers
{
    public static $order = 1;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        Numbers::nonNegative($entity, ['rate']);
    }
}
