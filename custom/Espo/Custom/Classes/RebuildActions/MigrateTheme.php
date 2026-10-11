<?php

namespace Espo\Custom\Classes\RebuildActions;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Custom\Tools\Theme\Migrator;

/** Runs the idempotent theme-id migration on every rebuild (see Tools\Theme\Migrator). */
class MigrateTheme implements RebuildAction
{
    public function __construct(private Migrator $migrator) {}

    public function process(): void
    {
        $this->migrator->run();
    }
}
