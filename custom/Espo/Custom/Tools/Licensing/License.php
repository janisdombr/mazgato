<?php

namespace Espo\Custom\Tools\Licensing;

use Espo\Core\Utils\Config;

/**
 * The installation's licence: where the file lives, the installation id, and the verdict.
 *
 *  - licence file   data/license.json (uploaded in Administration -> Licence or copied by hand)
 *  - installation   data/installation-id, minted once (random, 32 hex); the customer sends it
 *                   to the vendor, who binds the licence to it
 *  - public key     config `licensePublicKey` (base64, 32 bytes), else the vendor constant below.
 *                   The private key is NEVER in this repository.
 *
 * This is a commercial control, not DRM: the code is AGPL and whoever runs it may change it
 * (docs/AGPL.md). There is no phone-home - verification is offline.
 */
class License
{
    /** Vendor's Ed25519 public key (base64). Empty until the vendor generates its pair (docs/LICENSING.md). */
    public const VENDOR_PUBLIC_KEY = '';

    private ?array $verdict = null;

    public function __construct(private Config $config)
    {}

    private function dataDir(): string
    {
        return dirname(__DIR__, 5) . '/data';
    }

    public function filePath(): string
    {
        return $this->dataDir() . '/license.json';
    }

    public function installationId(): string
    {
        $path = $this->dataDir() . '/installation-id';
        $id = is_file($path) ? trim((string) file_get_contents($path)) : '';

        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            $id = bin2hex(random_bytes(16));
            @file_put_contents($path, $id . "\n", LOCK_EX);
            @chmod($path, 0640);
        }

        return $id;
    }

    public function publicKey(): string
    {
        $k = trim((string) $this->config->get('licensePublicKey'));

        return $k !== '' ? $k : self::VENDOR_PUBLIC_KEY;
    }

    public function enforced(): bool
    {
        return $this->config->get('licenseEnforced') === true;
    }

    public function read(): ?string
    {
        $c = is_file($this->filePath()) ? file_get_contents($this->filePath()) : false;

        return $c === false ? null : $c;
    }

    /** @return array{status: string, payload: ?array<string, mixed>, expiresAt: ?int, graceUntil: ?int} */
    public function verdict(): array
    {
        return $this->verdict ??= LicenseVerifier::check($this->read(), $this->publicKey(), $this->installationId(), time());
    }

    /** Check a candidate file without installing it. */
    public function checkContent(string $content): array
    {
        return LicenseVerifier::check($content, $this->publicKey(), $this->installationId(), time());
    }

    public function install(string $content): bool
    {
        $ok = @file_put_contents($this->filePath(), $content, LOCK_EX) !== false;
        $this->verdict = null;

        return $ok;
    }

    public function remove(): void
    {
        if (is_file($this->filePath())) {
            @unlink($this->filePath());
        }

        $this->verdict = null;
    }

    /** May the licence switch this module on? (Only meaningful when enforced.) */
    public function covers(string $module): bool
    {
        $v = $this->verdict();

        return in_array($v['status'], ['valid', 'grace'], true)
            && in_array($module, $v['payload']['modules'] ?? [], true);
    }
}
