<?php

namespace Espo\Custom\Tools\Licensing;

use Espo\Core\Utils\Config;

/**
 * The ONE place that answers "may this installation use module X?".
 *
 * Today the answer comes from the Settings multi-enum `enabledModules`:
 *   - the setting was never saved (null)  -> every module is on (local / on-prem default),
 *     except the DEFAULT_OFF ones (the AI assistants and the accounting integration - both send data to an outside service);
 *   - a list                              -> only the listed modules are on ([] = none).
 *
 * With config `licenseEnforced` = true the answer is instead: the module is in a SIGNED,
 * unexpired (or within-grace) licence for THIS installation (see License / LicenseVerifier).
 * The `enabledModules` setting then only switches OFF what the licence would allow
 * (an operator may still turn a licensed module off). Callers only ever ask
 * `moduleEnabled('kiosk')`, so no endpoint or view needs to change.
 */
class Modules
{
    public const KIOSK = 'kiosk';
    public const NOTIFY = 'notify';
    public const BOOKING = 'booking';
    public const ASSISTANT = 'assistant';
    public const ANPR = 'anpr';
    public const CAMERAS = 'cameras';
    public const CLIENT_ASSISTANT = 'clientAssistant';
    public const ACCOUNTING = 'accounting';
    public const LOYALTY = 'loyalty';

    public const ALL = [self::KIOSK, self::NOTIFY, self::BOOKING, self::ASSISTANT, self::ANPR, self::CAMERAS, self::CLIENT_ASSISTANT, self::ACCOUNTING, self::LOYALTY];

    /**
     * Modules that are OFF on an installation whose `enabledModules` was never saved. The two AI
     * assistants spend money with an external provider and the public one faces the internet, so
     * they must be switched on on purpose (a saved list names what is on, as before).
     */
    public const DEFAULT_OFF = [self::ASSISTANT, self::CLIENT_ASSISTANT, self::ACCOUNTING];

    public function __construct(private Config $config, private License $license)
    {}

    public function moduleEnabled(string $module): bool
    {
        if ($this->license->enforced() && !$this->license->covers($module)) {
            return false;
        }

        $list = $this->config->get('enabledModules');

        if ($list === null) {
            return !in_array($module, self::DEFAULT_OFF, true);
        }

        return is_array($list) && in_array($module, $list, true);
    }
}
