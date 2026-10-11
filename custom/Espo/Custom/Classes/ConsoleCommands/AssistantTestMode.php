<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

/**
 * DEV / TEST ONLY. Lets tests/run.sh drive the assistant without a real provider or network:
 *
 *     bin/command assistant-test-mode on  --provider=scripted
 *     bin/command assistant-test-mode on  --provider=anthropic --base=http://host.docker.internal:18992 --key=sk-test-…
 *     bin/command assistant-test-mode off
 *
 * `on` remembers the real provider settings and `off` puts them back exactly (the real key is
 * never printed, and a test key is never left behind). Without test mode the scripted provider and
 * a custom API base are ignored by the code, so this cannot be switched on from the browser.
 */
class AssistantTestMode implements Command
{
    private const SAVED = ['assistantProvider', 'assistantApiKey', 'assistantApiBase'];

    public function __construct(private Config $config, private ConfigWriter $writer)
    {}

    public function run(Params $params, IO $io): void
    {
        $mode = $params->getArgument(0);

        if ($mode === 'on') {
            if ($this->config->get('assistantTestMode') !== true) {
                $this->writer->set('assistantBackup', array_map(fn($k) => $this->config->get($k), array_combine(self::SAVED, self::SAVED)));
            }

            $this->writer->set('assistantTestMode', true);
            $this->writer->set('assistantProvider', (string) ($params->getOption('provider') ?: 'scripted'));
            $this->writer->set('assistantApiBase', (string) ($params->getOption('base') ?: ''));

            if ($params->getOption('key')) {
                $this->writer->set('assistantApiKey', (string) $params->getOption('key'));
            }

            $this->writer->save();
            $io->writeLine('Assistant test mode: on (' . $this->config->get('assistantProvider') . ')');

            return;
        }

        if ($mode === 'off') {
            $b = (array) $this->config->get('assistantBackup', []);

            foreach (self::SAVED as $k) {
                if (array_key_exists($k, $b) && $b[$k] !== null) {
                    $this->writer->set($k, $b[$k]);
                } else {
                    $this->writer->remove($k);
                }
            }

            $this->writer->remove('assistantBackup');
            $this->writer->remove('assistantTestMode');
            $this->writer->save();
            $io->writeLine('Assistant test mode: off (settings restored)');

            return;
        }

        $io->writeLine('usage: assistant-test-mode on [--provider=scripted|anthropic] [--base=URL] [--key=KEY] | off');
    }
}
