<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\Theme\Migrator;

/** bin/command migrate-theme — idempotent; also runs automatically as a rebuild action. */
class MigrateTheme implements Command
{
    public function __construct(private Migrator $migrator) {}

    public function run(Params $params, IO $io): void
    {
        $r = $this->migrator->run();
        $io->writeLine('Settings.theme migrated: ' . ($r['settings'] ? 'yes' : 'no (already current)') . '; preferences migrated: ' . $r['preferences']);
    }
}
