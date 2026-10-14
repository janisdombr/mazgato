<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Accounting\SyncService;

/** Every 5 minutes: queue the orders the hook missed (auto / batch mode), then send what is due. Never throws. */
class AccountingSync implements JobDataLess
{
    public function __construct(private SyncService $sync)
    {}

    public function run(): void
    {
        try {
            $this->sync->backfill(100);
            $this->sync->run(30);
        } catch (\Throwable $e) {
            // the next tick tries again
        }
    }
}
