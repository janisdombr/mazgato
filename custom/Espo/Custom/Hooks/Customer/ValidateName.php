<?php

namespace Espo\Custom\Hooks\Customer;

use Espo\Custom\Tools\Data\Parties;
use Espo\ORM\Entity;

/**
 * A customer always has a name (400 `customer_name_required`) - the minimum that identifies a payer. Every path
 * (entity API, createOrder, booking, e-mail, import) goes through here; the UI only suggests.
 */
class ValidateName
{
    public static $order = 1;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if ($entity->isNew() || $entity->isAttributeChanged('name')) {
            $entity->set('name', Parties::requireName((string) $entity->get('name')));
        }
    }
}
