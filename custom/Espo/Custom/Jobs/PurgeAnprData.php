<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Anpr\Retention;

/**
 * Daily privacy sweep of the plate / camera data (snapshots, events, frames, access journal).
 * Periods are Settings; see docs/ANPR.md. Also `bin/command anpr-purge`.
 */
class PurgeAnprData implements JobDataLess
{
    public function __construct(private Retention $retention)
    {}

    public function run(): void
    {
        $this->retention->purge();
    }
}
