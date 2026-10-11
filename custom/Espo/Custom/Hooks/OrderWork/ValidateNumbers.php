<?php

namespace Espo\Custom\Hooks\OrderWork;

use Espo\Custom\Tools\Validation\Numbers;
use Espo\ORM\Entity;

/**
 * An order-work line: rate and amount are not negative (no code path books a negative wage — a correction is
 * done by editing the line), `share` is a percentage in (0, 100]. Runs before CalculateAmount.
 */
class ValidateNumbers
{
    public static $order = 1;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        Numbers::nonNegative($entity, ['rate', 'amount']);

        $share = $entity->get('share');

        if (($share !== null && $share !== '') && ($entity->isNew() || $entity->isAttributeChanged('share'))) {
            Numbers::assertNumber('share', $share, 0.0, 100.0, true);
        }
    }
}
