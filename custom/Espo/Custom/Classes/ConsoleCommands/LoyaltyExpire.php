<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\Loyalty\LoyaltyConfig;
use Espo\Custom\Tools\Loyalty\Points;
use Espo\Custom\Tools\Loyalty\Referral;

/**
 * Write off expired loyalty points and release referral rewards whose hold has passed (the same routine as the daily job LoyaltyExpire).
 *
 *     bin/command loyalty-expire             # do it
 *     bin/command loyalty-expire --dry-run   # only count
 */
class LoyaltyExpire implements Command
{
    public function __construct(private Points $points, private Referral $referral, private LoyaltyConfig $cfg)
    {}

    public function run(Params $params, IO $io): void
    {
        $dry = $params->hasFlag('dryRun');

        if (!$this->cfg->enabled()) {
            $io->writeLine('The loyalty module is off: nothing to do.');

            return;
        }

        $e = $this->points->expireAll($dry);
        $r = $this->referral->releaseDue($dry);

        $io->writeLine(sprintf('%s: customers=%d expiredPoints=%d referralsReleased=%d', $dry ? 'Dry run, nothing changed' : 'Done', $e['customers'], $e['points'], $r));
    }
}
