<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\Licensing\License;

/** bin/command license-status - installation id (to send to the vendor) and the licence verdict. */
class LicenseStatus implements Command
{
    public function __construct(private License $license)
    {}

    public function run(Params $params, IO $io): void
    {
        $v = $this->license->verdict();

        $io->writeLine('installationId: ' . $this->license->installationId());
        $io->writeLine('enforced: ' . ($this->license->enforced() ? 'yes' : 'no'));
        $io->writeLine('status: ' . $v['status']);

        if ($v['payload']) {
            $io->writeLine('customer: ' . ($v['payload']['customer'] ?? ''));
            $io->writeLine('modules: ' . implode(',', $v['payload']['modules']));
            $io->writeLine('expires: ' . ($v['payload']['expires'] ?? ''));
        }
    }
}
