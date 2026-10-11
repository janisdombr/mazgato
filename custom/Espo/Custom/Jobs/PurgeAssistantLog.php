<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Assistant\Journal;

/** Daily retention sweep of the assistant journal (scheduled in metadata/app/scheduledJobs.json). */
class PurgeAssistantLog implements JobDataLess
{
    public function __construct(private Journal $journal)
    {}

    public function run(): void
    {
        $this->journal->purge();
    }
}
