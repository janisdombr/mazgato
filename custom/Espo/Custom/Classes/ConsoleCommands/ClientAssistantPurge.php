<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\ClientAssistant\ClientJournal;

/**
 * Delete public chat journal rows older than the retention (Settings `clientAssistantRetentionDays`,
 * default 31, never below 31). The same routine runs daily as the scheduled job PurgeClientChatLog.
 *
 *     bin/command client-assistant-purge             # delete
 *     bin/command client-assistant-purge --dry-run   # only count
 *     bin/command client-assistant-purge --days=45   # one-off, shorter (not below 31)
 */
class ClientAssistantPurge implements Command
{
    public function __construct(private ClientJournal $journal)
    {}

    public function run(Params $params, IO $io): void
    {
        $dry = $params->hasFlag('dryRun');
        $days = $params->getOption('days');
        $days = is_numeric($days) ? max(31, (int) $days) : null;
        $n = $this->journal->purge($dry, $days);

        $io->writeLine($dry ? "$n public chat journal row(s) are past retention (dry run, nothing deleted)." : "Deleted $n public chat journal row(s).");
    }
}
