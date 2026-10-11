<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Roles\RoleSeeder;

/**
 * Create the Operator and Manager roles (idempotent; see Tools/Roles/RoleSeeder).
 *
 *     bin/command seed-roles                 # names in the system language
 *     bin/command seed-roles --lang=ru_RU
 *     bin/command seed-roles --update        # also rewrite the rights of roles that already exist
 */
class SeedRoles implements Command
{
    public function __construct(private RoleSeeder $seeder, private Config $config)
    {}

    public function run(Params $params, IO $io): void
    {
        $lang = (string) ($params->getOption('lang') ?: $this->config->get('language') ?: 'lv_LV');

        foreach ($this->seeder->seed($lang, $params->hasFlag('update')) as $kind => $state) {
            $io->writeLine("role $kind: $state");
        }
    }
}
