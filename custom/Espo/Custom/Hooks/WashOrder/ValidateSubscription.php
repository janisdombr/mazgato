<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Custom\Tools\Subscriptions\Subscriptions;
use Espo\ORM\Entity;

/**
 * An order may be paid by a subscription only if that subscription can really pay it (same customer, not
 * expired / exhausted / cancelled, covers the service). Refused with 409 `subscription_<reason>` BEFORE anything is
 * stored. The rule itself lives in Tools/Subscriptions.
 */
class ValidateSubscription
{
    public static $order = 12;

    public function __construct(private Subscriptions $subscriptions)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $this->subscriptions->assertUsable($entity);
    }
}
