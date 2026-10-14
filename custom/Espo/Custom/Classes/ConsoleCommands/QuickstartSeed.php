<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\QuickStart\Starter;

/**
 * bin/command quickstart-seed [--lang=lv_LV|ru_RU|en_US|lt_LT] [--force] [--reset]
 *
 * Loads the starter catalogue (docs/QUICKSTART.md): idempotent by name, never edits what exists. Skipped when it
 * was already loaded on this installation (marker) unless --force. --reset only clears the marker.
 * Used by setup.sh (SEED_STARTER=1) and scripts/tenant-provision --seed starter.
 */
class QuickstartSeed implements Command
{
    public function __construct(private Starter $starter)
    {}

    public function run(Params $params, IO $io): void
    {
        if ($params->hasFlag('reset')) {
            $this->starter->resetMarker();
            $io->writeLine('marker cleared');

            return;
        }

        if ($this->starter->loadedAt() && !$params->hasFlag('force')) {
            $io->writeLine('starter set already loaded (' . $this->starter->loadedAt() . ') - skipped; use --force to add missing items');

            return;
        }

        $lang = $params->getOption('lang') ?: null;

        if ($lang !== null && !in_array($lang, Starter::LANGS, true)) {
            $io->writeLine('error: --lang must be lv_LV, ru_RU, en_US or lt_LT');
            $io->setExitStatus(2);

            return;
        }

        $r = $this->starter->load($lang);
        $io->writeLine('starter set (' . $r['lang'] . '): created ' . json_encode($r['created']) . ', already there ' . json_encode($r['skipped']));
    }
}
