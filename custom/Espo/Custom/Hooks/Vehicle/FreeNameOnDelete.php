<?php

namespace Espo\Custom\Hooks\Vehicle;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;

/**
 * The plate is unique over (name, deleted). Soft-delete flips `deleted` to 1, so deleting a
 * SECOND vehicle that once had the same plate (create, delete, re-create, delete) collided
 * with the first tombstone and answered 500. Give the tombstone its own name — the plate
 * becomes "KE1234~<id>" — so the key is free again. Active vehicles are untouched.
 */
class FreeNameOnDelete
{
    public static $order = 5;

    public function __construct(private EntityManager $entityManager)
    {}

    public function beforeRemove(Entity $entity, array $options = []): void
    {
        $name = (string) $entity->get('name');

        if ($name === '' || str_contains($name, '~')) {
            return;
        }

        $update = $this->entityManager->getQueryBuilder()
            ->update()
            ->in('Vehicle')
            ->set(['name' => $name . '~' . $entity->getId()])
            ->where(Cond::equal(Cond::column('id'), $entity->getId()))
            ->build();

        $this->entityManager->getQueryExecutor()->execute($update);
    }
}
