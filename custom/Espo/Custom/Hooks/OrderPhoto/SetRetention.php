<?php

namespace Espo\Custom\Hooks\OrderPhoto;

use Espo\Core\Utils\Config;
use Espo\ORM\Entity;

/**
 * Stamp the day after which a photo is deleted: today + `photoRetentionDays` (Settings,
 * default 30). A date typed by hand is kept. Cleanup: `bin/command purge-photos`.
 */
class SetRetention
{
    public static $order = 5;

    public function __construct(private Config $config)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (!$entity->isNew() || $entity->get('retainUntil')) {
            return;
        }

        $days = max(1, (int) ($this->config->get('photoRetentionDays') ?: 30));

        $entity->set('retainUntil', gmdate('Y-m-d', time() + $days * 86400));

        if (!$entity->get('takenAt')) {
            $entity->set('takenAt', gmdate('Y-m-d H:i:s'));
        }
    }
}
