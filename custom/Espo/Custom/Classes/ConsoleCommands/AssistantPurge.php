<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\Assistant\Journal;

/**
 * Delete assistant journal rows older than the retention (Settings `assistantRetentionDays`,
 * default 90, never below 31). The same routine runs daily as the scheduled job PurgeAssistantLog.
 *
 *     bin/command assistant-purge             # delete
 *     bin/command assistant-purge --dry-run   # only count
 *     bin/command assistant-purge --days=45   # one-off, shorter (not below 31)
 */
class AssistantPurge implements Command
{
    public function __construct(private Journal $journal)
    {}

    public function run(Params $params, IO $io): void
    {
        $dry = $params->hasFlag('dryRun');
        $days = $params->getOption('days');
        $days = is_numeric($days) ? max(31, (int) $days) : null;
        $n = $this->journal->purge($dry, $days);

        $io->writeLine($dry ? "$n assistant journal row(s) are past retention (dry run, nothing deleted)." : "Deleted $n assistant journal row(s).");
    }
}
