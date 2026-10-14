<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\Accounting\SyncService;

/**
 * The accounting queue from the command line (also what the scheduled job does):
 *
 *     bin/command accounting-sync run [--force]            send what is due (--force ignores the batch window)
 *     bin/command accounting-sync enqueue --from=YYYY-MM-DD --to=YYYY-MM-DD   queue completed orders of those days
 *     bin/command accounting-sync status --from=... --to=...                  counts, failed, missing, drift
 */
class AccountingSync implements Command
{
    public function __construct(private SyncService $sync)
    {}

    public function run(Params $params, IO $io): void
    {
        $a = $params->getArgument(0);
        $from = (string) ($params->getOption('from') ?: date('Y-m-01'));
        $to = (string) ($params->getOption('to') ?: date('Y-m-d'));

        if ($a === 'run') {
            $io->writeLine(json_encode($this->sync->run(30, $params->hasFlag('force'))));

            return;
        }

        if ($a === 'enqueue') {
            $io->writeLine('queued ' . $this->sync->enqueueRange($from, $to));

            return;
        }

        if ($a === 'status') {
            $io->writeLine(json_encode($this->sync->summary($from, $to), JSON_UNESCAPED_UNICODE));

            return;
        }

        $io->writeLine('usage: accounting-sync run [--force] | enqueue --from=D --to=D | status --from=D --to=D');
    }
}
