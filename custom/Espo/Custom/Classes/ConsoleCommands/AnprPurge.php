<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\Anpr\Retention;

/**
 * Privacy clean-up of the plate / camera data: snapshots and events past their retention,
 * old on-demand frames, the old access journal. The same routine runs daily as the job
 * PurgeAnprData.
 *
 *     bin/command anpr-purge             # delete
 *     bin/command anpr-purge --dry-run   # only count
 */
class AnprPurge implements Command
{
    public function __construct(private Retention $retention)
    {}

    public function run(Params $params, IO $io): void
    {
        $dry = $params->hasFlag('dryRun');
        $r = $this->retention->purge($dry);

        $io->writeLine(sprintf(
            '%s: snapshots=%d events=%d frames=%d accessLog=%d commandsExpired=%d',
            $dry ? 'Dry run, nothing deleted' : 'Deleted',
            $r['snapshots'],
            $r['events'],
            $r['frames'],
            $r['accessLog'],
            $r['commandsExpired']
        ));
    }
}
