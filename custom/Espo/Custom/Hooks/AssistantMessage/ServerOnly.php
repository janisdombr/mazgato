<?php

namespace Espo\Custom\Hooks\AssistantMessage;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

/**
 * The assistant journal is written by the assistant service and nobody else: it records what was
 * asked and what the model was given, so it must not be forgeable or editable through the entity
 * API (a role with "create" or "edit" would otherwise be able to). Reading and deleting own
 * entries stay ordinary ACL matters.
 */
class ServerOnly
{
    public static $order = 1;

    /** @throws Forbidden */
    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (empty($options['assistantWrite'])) {
            throw new Forbidden('The assistant journal is written by the assistant only.');
        }
    }
}
