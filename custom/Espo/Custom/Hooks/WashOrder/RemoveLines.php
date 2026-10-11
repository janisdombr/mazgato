<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;

/**
 * Deleting an order takes its dependants with it: the works (OrderWork), the priced lines (OrderAddon) and the "car ready" log
 * (OrderMessage). EspoCRM's soft delete removes only the order itself, so every deleted order used to leave live rows behind -
 * lines nobody can see (their order is gone) that still sit in the tables, in the seed dump and in SQL reports. This was also
 * the main way test runs polluted a database.
 *
 * A direct UPDATE (no entity hooks): the lines' own hooks re-price / re-derive the ORDER, which is exactly what must not happen
 * to an order that is being removed. Photos are not touched (files have their own retention).
 */
class RemoveLines
{
    public static $order = 9;

    private const CHILDREN = ['OrderWork', 'OrderAddon', 'OrderMessage'];

    public function __construct(private EntityManager $entityManager)
    {}

    public function afterRemove(Entity $entity, array $options = []): void
    {
        foreach (self::CHILDREN as $type) {
            $update = $this->entityManager->getQueryBuilder()
                ->update()
                ->in($type)
                ->set(['deleted' => true])
                ->where(Cond::equal(Cond::column('washOrderId'), $entity->getId()))
                ->where(Cond::equal(Cond::column('deleted'), false))
                ->build();

            $this->entityManager->getQueryExecutor()->execute($update);
        }
    }
}
