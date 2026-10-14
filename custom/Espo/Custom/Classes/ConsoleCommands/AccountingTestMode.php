<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

/**
 * DEV / TEST ONLY. Points the accounting connectors at a local FAKE server (tests/mock-accounting.py) and installs test
 * credentials, so the tests never reach a real provider:
 *
 *     bin/command accounting-test-mode on --base=http://host:port --provider=merit|smartaccounts [--id=ID --key=KEY]
 *     bin/command accounting-test-mode off
 *
 * `on` remembers the real settings, `off` puts them back exactly. Without test mode the provider URLs are fixed constants,
 * so this cannot be switched on from the browser.
 */
class AccountingTestMode implements Command
{
    private const SAVED = [
        'accountingProvider', 'accountingMode', 'accountingStartDate', 'accountingMeritId', 'accountingMeritKey', 'accountingSmartId', 'accountingSmartKey',
        'accountingPayMethodCash', 'accountingPayMethodCard', 'accountingPayAccountCash', 'accountingPayAccountCard', 'accountingMeritIban',
        'accountingVatId21', 'accountingVatId0', 'accountingCache', 'accountingRetailName', 'accountingBatchTime',
    ];

    public function __construct(private Config $config, private ConfigWriter $writer)
    {}

    public function run(Params $params, IO $io): void
    {
        $mode = $params->getArgument(0);

        if ($mode === 'on') {
            if ($this->config->get('accountingTestMode') !== true) {
                $this->writer->set('accountingBackup', array_map(fn($k) => $this->config->get($k), array_combine(self::SAVED, self::SAVED)));
            }

            $provider = (string) ($params->getOption('provider') ?: 'merit');
            $id = (string) ($params->getOption('id') ?: 'test-id-12345678');
            $key = (string) ($params->getOption('key') ?: 'test-secret-key-987654');
            $this->writer->set('accountingTestMode', true);
            $this->writer->set('accountingTestBase', (string) ($params->getOption('base') ?: ''));
            $this->writer->set('accountingProvider', $provider);
            $this->writer->set('accountingMeritId', $id);
            $this->writer->set('accountingMeritKey', $key);
            $this->writer->set('accountingSmartId', $id);
            $this->writer->set('accountingSmartKey', $key);
            $this->writer->set('accountingCache', []);
            $this->writer->save();
            $io->writeLine('Accounting test mode: on (' . $provider . ')');

            return;
        }

        if ($mode === 'off') {
            $b = (array) $this->config->get('accountingBackup', []);

            foreach (self::SAVED as $k) {
                if (array_key_exists($k, $b) && $b[$k] !== null) {
                    $this->writer->set($k, $b[$k]);
                } else {
                    $this->writer->remove($k);
                }
            }

            foreach (['accountingBackup', 'accountingTestMode', 'accountingTestBase'] as $k) {
                $this->writer->remove($k);
            }

            $this->writer->save();
            $io->writeLine('Accounting test mode: off (settings restored)');

            return;
        }

        $io->writeLine('usage: accounting-test-mode on --base=URL --provider=merit|smartaccounts [--id=ID --key=KEY] | off');
    }
}
