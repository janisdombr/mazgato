<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

/**
 * DEV / TEST ONLY (CLI; the browser cannot reach it). Lets tests/run.sh exercise the licence gate
 * with a throw-away key pair:
 *
 *     bin/command license-test-mode on --pubkey=<base64 ed25519 public key>   (also enforces)
 *     bin/command license-test-mode off                                       (restores exactly)
 *
 * `on` remembers the real `licensePublicKey` / `licenseEnforced`; `off` puts them back.
 */
class LicenseTestMode implements Command
{
    private const SAVED = ['licensePublicKey', 'licenseEnforced'];

    public function __construct(private Config $config, private ConfigWriter $writer)
    {}

    public function run(Params $params, IO $io): void
    {
        $mode = $params->getArgument(0);

        if ($mode === 'on') {
            if ($this->config->get('licenseBackup') === null) {
                $this->writer->set('licenseBackup', array_map(fn($k) => $this->config->get($k), array_combine(self::SAVED, self::SAVED)));
            }

            $this->writer->set('licensePublicKey', (string) $params->getOption('pubkey'));
            $this->writer->set('licenseEnforced', true);
            $this->writer->save();
            $io->writeLine('License test mode: on (enforced)');

            return;
        }

        if ($mode === 'off') {
            $b = (array) $this->config->get('licenseBackup', []);

            foreach (self::SAVED as $k) {
                if (array_key_exists($k, $b) && $b[$k] !== null) {
                    $this->writer->set($k, $b[$k]);
                } else {
                    $this->writer->remove($k);
                }
            }

            $this->writer->remove('licenseBackup');
            $this->writer->save();
            $io->writeLine('License test mode: off (settings restored)');

            return;
        }

        $io->writeLine('usage: license-test-mode on --pubkey=BASE64 | off');
    }
}
