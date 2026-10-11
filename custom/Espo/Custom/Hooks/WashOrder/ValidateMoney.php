<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Validation\Numbers;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The total typed on an order must be a finite number >= 0. It MAY be lower than the add-ons it contains,
 * down to 0: that is a discount, and the lines are scaled to it (Tools/OrderPrice/Distributor, applied by
 * SyncOrderAddons::applyManualTotal). Only a negative or non-numeric total is refused (400).
 */
class ValidateMoney
{
    public static $order = 2;

    public function __construct(private EntityManager $em)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (!$entity->isNew() && !$entity->isAttributeChanged('finalPrice')) {
            return;
        }

        $v = $entity->get('finalPrice');

        if ($v === null || $v === '') {
            return;
        }

        Numbers::assertNumber('finalPrice', $v);
    }
}
