<?php

namespace Espo\Custom\Tools\Accounting;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Custom\Tools\Assistant\Secret;

/**
 * Every accounting-integration setting in one place. Secrets (`*Key`) are read as a `Secret` that prints as "[key]" and are
 * removed from any text by `scrub()` before it reaches a ledger row, a log or an HTTP answer. The keys live in
 * data/config-internal.php (metadata/app/config.json, level internal) and are never sent to the browser.
 *
 * Provider base URLs are fixed constants in the connectors. They can be redirected to a fake server ONLY while the
 * installation is in accounting test mode, which is switched on from the command line (`bin/command accounting-test-mode`),
 * never from the browser - so the admin page cannot be turned into a way to make the server call an arbitrary address.
 */
class Cfg
{
    public const SECRET_KEYS = ['accountingMeritKey', 'accountingSmartKey'];

    /** @var array<string, mixed> values written during this process (the Config object does not see its own writes) */
    private array $mem = [];

    public function __construct(private Config $config, private ?ConfigWriter $writer = null)
    {}

    public function get(string $key, mixed $default = null): mixed
    {
        $v = $this->config->get($key, $default);

        return $v === null ? $default : $v;
    }

    public function str(string $key, string $default = ''): string
    {
        return trim((string) $this->get($key, $default));
    }

    public function secret(string $key): Secret
    {
        return new Secret(trim((string) $this->config->get($key, '')));
    }

    public function provider(): string
    {
        return $this->str('accountingProvider');
    }

    public function mode(): string
    {
        $m = $this->str('accountingMode', 'manual');

        return in_array($m, ['manual', 'auto', 'batch'], true) ? $m : 'manual';
    }

    public function testBase(): ?string
    {
        if ($this->config->get('accountingTestMode') !== true) {
            return null;
        }

        $b = trim((string) $this->config->get('accountingTestBase', ''));

        return $b === '' ? null : rtrim($b, '/');
    }

    public function timeZone(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->config->get('timeZone', 'UTC') ?: 'UTC');
    }

    /** Remove every configured secret and the usual credential query parameters from a text. */
    public function scrub(string $text): string
    {
        foreach (self::SECRET_KEYS as $k) {
            $text = $this->secret($k)->scrub($text);
        }

        foreach (['accountingMeritId', 'accountingSmartId'] as $k) {
            $v = trim((string) $this->config->get($k, ''));

            if (strlen($v) >= 8) {
                $text = str_replace($v, '[id]', $text);
            }
        }

        $text = preg_replace('/(apikey|apiid|signature|api_key|token)=[^&\s"\']+/i', '$1=[x]', $text) ?? $text;
        $text = preg_replace('#https?://[^\s"\']+#i', '[url]', $text) ?? $text;

        return $text;
    }

    /** Small non-secret cache (VAT ids, ensured item codes, the general customer's id), kept in config. */
    public function cache(string $provider, string $key): mixed
    {
        if (array_key_exists($provider . '/' . $key, $this->mem)) {
            return $this->mem[$provider . '/' . $key];
        }

        $c = (array) $this->config->get('accountingCache', []);

        return $c[$provider][$key] ?? null;
    }

    public function cachePut(string $provider, string $key, mixed $value): void
    {
        if (!$this->writer) {
            return;
        }

        $this->mem[$provider . '/' . $key] = $value;
        $c = (array) $this->config->get('accountingCache', []);
        $c[$provider] = (array) ($c[$provider] ?? []);
        $c[$provider][$key] = $value;
        $this->writer->set('accountingCache', $c);
        $this->writer->save();
    }

    public function cacheClear(): void
    {
        if ($this->writer) {
            $this->writer->set('accountingCache', []);
            $this->writer->save();
        }
    }
}
