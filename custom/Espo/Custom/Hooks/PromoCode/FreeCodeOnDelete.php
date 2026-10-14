<?php

namespace Espo\Custom\Hooks\PromoCode;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The code is unique over (code, deleted): a deleted code's tombstone gets its own name ("SPRING5~<id>"), so the
 * same code can be created again later (same trick as Vehicle plates).
 */
class FreeCodeOnDelete
{
    public static $order = 5;

    public function __construct(private EntityManager $em)
    {}

    public function beforeRemove(Entity $entity, array $options = []): void
    {
        $code = (string) $entity->get('code');

        if ($code === '' || str_contains($code, '~')) {
            return;
        }

        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in('PromoCode')->set(['code' => $code . '~' . $entity->getId()])->where(['id' => $entity->getId()])->build()
        );
    }
}
