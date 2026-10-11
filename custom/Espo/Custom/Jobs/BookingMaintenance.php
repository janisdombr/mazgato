<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Booking\Maintenance;

/** Every 5 minutes: booking reminders and no-show release (scheduledJobs.json). Needs cron. */
class BookingMaintenance implements JobDataLess
{
    public function __construct(private Maintenance $maintenance) {}

    public function run(): void
    {
        $this->maintenance->run();
    }
}
