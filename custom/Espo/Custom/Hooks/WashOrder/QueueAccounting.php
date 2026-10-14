<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Custom\Tools\Accounting\SyncService;
use Espo\ORM\Entity;

/**
 * Auto / batch mode of the accounting integration: when an order is Completed (or paid afterwards), put a Pending row in the
 * queue. Only a row - the network call is made by the scheduled job, so a slow or broken accounting program can never slow
 * down or fail an order save. Everything is swallowed on purpose.
 */
class QueueAccounting
{
    public static $order = 95;

    public function __construct(private SyncService $sync)
    {}

    public function afterSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['skipAccounting']) || $entity->get('status') !== 'Completed') {
            return;
        }

        try {
            $this->sync->onOrderSaved($entity);
        } catch (\Throwable $e) {
            // the order is saved; the job's backfill will pick the order up
        }
    }
}
