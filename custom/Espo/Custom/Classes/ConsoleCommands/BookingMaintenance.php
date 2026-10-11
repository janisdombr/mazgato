<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\Booking\Maintenance;

/** bin/command booking-maintenance — reminders + no-show release (same routine as the job). */
class BookingMaintenance implements Command
{
    public function __construct(private Maintenance $maintenance) {}

    public function run(Params $params, IO $io): void
    {
        $r = $this->maintenance->run();
        $io->writeLine("reminded={$r['reminded']} noShow={$r['noShow']}");
    }
}
