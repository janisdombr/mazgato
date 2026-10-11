<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Kiosk\PhotoRetention;

/**
 * Daily retention sweep for order photos (scheduled in metadata/app/scheduledJobs.json).
 */
class PurgeExpiredPhotos implements JobDataLess
{
    public function __construct(private PhotoRetention $retention)
    {}

    public function run(): void
    {
        $this->retention->purge();
    }
}
