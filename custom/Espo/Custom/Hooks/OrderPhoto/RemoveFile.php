<?php

namespace Espo\Custom\Hooks\OrderPhoto;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Deleting a photo record deletes its image file (the Attachment, whose own hook removes
 * the bytes from storage) — otherwise the retention policy would drop the row and keep
 * the picture on disk.
 */
class RemoveFile
{
    public static $order = 9;

    public function __construct(private EntityManager $entityManager)
    {}

    public function afterRemove(Entity $entity, array $options = []): void
    {
        $fileId = $entity->get('fileId');

        if (!$fileId) {
            return;
        }

        $attachment = $this->entityManager->getEntityById('Attachment', $fileId);

        if ($attachment) {
            $this->entityManager->removeEntity($attachment);
        }
    }
}
