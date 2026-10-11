<?php

namespace Espo\Custom\Tools\Theme;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\ORM\EntityManager;

/**
 * Idempotent theme-id migration after the product-name rename: the themes were renamed
 * (old id => CarWash / CarWashDark). Installed systems still carry the old id in
 * Settings.theme (config.php) and in every user's Preferences.theme; without this they would
 * fall back to the stock EspoCRM theme. Runs on every `rebuild` (so a normal deploy migrates
 * prod) and by hand: `bin/command migrate-theme`. A second run changes nothing.
 * The legacy ids below are intentionally the only place the old names survive (tests/name-allowlist.txt).
 */
class Migrator
{
    private const MAP = [
        'Nemo' => 'CarWash',
        'NemoDark' => 'CarWashDark',
    ];

    public function __construct(
        private Config $config,
        private ConfigWriter $configWriter,
        private EntityManager $entityManager
    ) {}

    /** @return array{settings: bool, preferences: int} */
    public function run(): array
    {
        $result = ['settings' => false, 'preferences' => 0];

        $theme = $this->config->get('theme');

        if (is_string($theme) && isset(self::MAP[$theme])) {
            $this->configWriter->set('theme', self::MAP[$theme]);
            $this->configWriter->save();
            $result['settings'] = true;
        }

        $pdo = $this->entityManager->getPDO();

        foreach ($pdo->query('SELECT id, data FROM preferences')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $data = json_decode((string) $row['data'], true);

            if (!is_array($data) || !isset($data['theme']) || !isset(self::MAP[$data['theme']])) {
                continue;
            }

            $data['theme'] = self::MAP[$data['theme']];

            $stmt = $pdo->prepare('UPDATE preferences SET data = :d WHERE id = :id');
            $stmt->execute([':d' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id' => $row['id']]);
            $result['preferences']++;
        }

        return $result;
    }
}
