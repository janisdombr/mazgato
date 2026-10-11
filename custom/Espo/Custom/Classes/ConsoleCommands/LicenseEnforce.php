<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

/**
 * bin/command license-enforce on|off  - server-side switch for the signed-licence gate (docs/LICENSING.md).
 * Deliberately not a Settings field: an administrator in the browser must not be able to flip it.
 */
class LicenseEnforce implements Command
{
    public function __construct(private Config $config, private ConfigWriter $writer)
    {}

    public function run(Params $params, IO $io): void
    {
        $mode = $params->getArgument(0);

        if ($mode !== 'on' && $mode !== 'off') {
            $io->writeLine('licenseEnforced: ' . ($this->config->get('licenseEnforced') === true ? 'on' : 'off') . '  (usage: license-enforce on|off)');

            return;
        }

        if ($mode === 'on') {
            $this->writer->set('licenseEnforced', true);
        } else {
            $this->writer->remove('licenseEnforced');
        }

        $this->writer->save();
        $io->writeLine('licenseEnforced: ' . $mode);
    }
}
