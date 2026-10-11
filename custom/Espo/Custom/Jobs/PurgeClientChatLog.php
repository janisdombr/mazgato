<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\ClientAssistant\ClientJournal;

/** Daily retention sweep of the public chat journal (scheduled in metadata/app/scheduledJobs.json). */
class PurgeClientChatLog implements JobDataLess
{
    public function __construct(private ClientJournal $journal)
    {}

    public function run(): void
    {
        $this->journal->purge();
    }
}
