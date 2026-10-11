<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

/**
 * DEV / TEST ONLY. Lets tests/turnstile-api.sh drive the Turnstile check against a local fake siteverify server:
 *
 *     bin/command turnstile-test-mode on --base=http://host:port/siteverify --secret=good-secret [--sitekey=1x000…AA] [--hostname=h]
 *     bin/command turnstile-test-mode off
 *
 * `on` remembers the real settings, `off` puts them back exactly (a real secret is never printed). Without test mode the
 * verify URL is fixed to Cloudflare's, so this cannot be switched on from the browser.
 */
class TurnstileTestMode implements Command
{
    private const SAVED = ['turnstileSecret', 'turnstileSiteKey', 'turnstileHostname', 'turnstileVerifyUrl'];

    public function __construct(private Config $config, private ConfigWriter $writer)
    {}

    public function run(Params $params, IO $io): void
    {
        $mode = $params->getArgument(0);

        if ($mode === 'on') {
            if ($this->config->get('turnstileTestMode') !== true) {
                $this->writer->set('turnstileBackup', array_map(fn($k) => $this->config->get($k), array_combine(self::SAVED, self::SAVED)));
            }

            $this->writer->set('turnstileTestMode', true);
            $this->writer->set('turnstileVerifyUrl', (string) ($params->getOption('base') ?: ''));
            $this->writer->set('turnstileSecret', (string) ($params->getOption('secret') ?: ''));
            $this->writer->set('turnstileSiteKey', (string) ($params->getOption('sitekey') ?: '1x00000000000000000000AA'));
            $this->writer->set('turnstileHostname', (string) ($params->getOption('hostname') ?: ''));
            $this->writer->save();
            $io->writeLine('Turnstile test mode: on');

            return;
        }

        if ($mode === 'off') {
            $b = (array) $this->config->get('turnstileBackup', []);

            foreach (self::SAVED as $k) {
                if (array_key_exists($k, $b) && $b[$k] !== null) {
                    $this->writer->set($k, $b[$k]);
                } else {
                    $this->writer->remove($k);
                }
            }

            $this->writer->remove('turnstileBackup');
            $this->writer->remove('turnstileTestMode');
            $this->writer->save();
            $io->writeLine('Turnstile test mode: off (settings restored)');

            return;
        }

        $io->writeLine('usage: turnstile-test-mode on --base=URL --secret=KEY [--sitekey=KEY] [--hostname=H] | off');
    }
}
