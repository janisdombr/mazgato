<?php

namespace Espo\Custom\Hooks\PlateEvent;

use Espo\Custom\Tools\Anpr\Media;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Deleting a plate event (the list's Remove, an API DELETE) deletes it for real: the snapshot
 * file and row, and the event row itself - a soft-deleted tombstone would keep the plate.
 */
class RemoveForGood
{
    public static $order = 9;

    public function __construct(private EntityManager $entityManager, private Media $media)
    {}

    public function afterRemove(Entity $entity, array $options = []): void
    {
        $this->media->destroy($entity->get('snapshotKey'));
        $this->entityManager->getRDBRepository('PlateEvent')->deleteFromDb($entity->getId());
    }
}
