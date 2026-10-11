<?php

namespace Espo\Custom\Tools\Assistant;

use Espo\Core\Utils\Config;

/**
 * Every assistant setting in one place, with the clamps that make a bad value harmless
 * (a hard ceiling on steps, time and tokens cannot be configured away).
 *
 * The API key lives ONLY in data/config-internal.php (`assistantApiKey`, level "internal" in
 * metadata/app/config.json, so the Settings API never returns it) and is handed out as a
 * `Secret`.
 */
class AssistantConfig
{
    public const PROVIDERS = ['off', 'anthropic'];
    public const PII_LEVELS = ['aggregate', 'names', 'contacts'];

    public const DEFAULT_MODEL = 'claude-sonnet-5-5';
    public const DEFAULT_FALLBACK = 'claude-haiku-5-5';

    /** Hard ceilings: the admin page can lower these, never raise them. */
    public const MAX_STEPS_CEILING = 8;
    public const TURN_SECONDS_CEILING = 120;
    public const QUESTION_CHARS = 1000;

    public const API_BASE = 'https://api.anthropic.com';

    public function __construct(private Config $config)
    {}

    private function int(string $key, int $default, int $min, int $max): int
    {
        $v = $this->config->get($key);

        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
        }

        return max($min, min($max, (int) $v));
    }

    private function modelId(string $key, string $default): string
    {
        $v = trim((string) $this->config->get($key, ''));

        return preg_match('/^claude-[a-z0-9.\-]{2,60}$/', $v) ? $v : $default;
    }

    /** Test mode is switched on from the command line only (bin/command assistant-test-mode). */
    public function testMode(): bool
    {
        return $this->config->get('assistantTestMode') === true;
    }

    /** off | anthropic | scripted (scripted only while test mode is on). */
    public function provider(): string
    {
        $p = (string) $this->config->get('assistantProvider', 'off');

        if ($p === 'scripted' && $this->testMode()) {
            return 'scripted';
        }

        return in_array($p, self::PROVIDERS, true) ? $p : 'off';
    }

    public function key(): Secret
    {
        return new Secret(trim((string) $this->config->get('assistantApiKey', '')));
    }

    public function isConfigured(): bool
    {
        return match ($this->provider()) {
            'anthropic' => $this->key()->isSet(),
            'scripted' => true,
            default => false,
        };
    }

    public function apiBase(): string
    {
        $b = rtrim((string) $this->config->get('assistantApiBase', ''), '/');

        // A different endpoint is for the test mock only: a key must never be sent to an arbitrary host.
        return $this->testMode() && preg_match('#^https?://[^\s]+$#', $b) ? $b : self::API_BASE;
    }

    public function model(): string { return $this->modelId('assistantModel', self::DEFAULT_MODEL); }

    public function fallbackModel(): string { return $this->modelId('assistantFallbackModel', self::DEFAULT_FALLBACK); }

    public function effort(): string
    {
        $e = (string) $this->config->get('assistantEffort', 'low');

        // 'none' (or empty) = do not send the parameter at all
        return $e === 'none' || $e === '' ? '' : (in_array($e, ['low', 'medium', 'high'], true) ? $e : 'low');
    }

    public function maxSteps(): int { return $this->int('assistantMaxSteps', 6, 1, self::MAX_STEPS_CEILING); }

    public function turnSeconds(): int { return $this->int('assistantTurnSeconds', 50, 10, self::TURN_SECONDS_CEILING); }

    /** Input tokens one question may read across all its steps (cache reads included). */
    public function turnInputTokens(): int { return $this->int('assistantTurnTokens', 60000, 5000, 150000); }

    public function maxOutputTokens(): int { return $this->int('assistantMaxOutputTokens', 1024, 256, 4000); }

    public function dailyPerUser(): int { return $this->int('assistantDailyPerUser', 30, 1, 1000); }

    public function monthlyQuestions(): int { return $this->int('assistantMonthlyQuestions', 1000, 1, 100000); }

    public function monthlyTokens(): int { return $this->int('assistantMonthlyTokens', 15000000, 10000, 1000000000); }

    /** Retention of the journal. At least 31 days: the monthly limits are summed from it. */
    public function retentionDays(): int { return $this->int('assistantRetentionDays', 90, 31, 730); }

    public function piiLevel(): string
    {
        $p = (string) $this->config->get('assistantPiiLevel', 'aggregate');

        return in_array($p, self::PII_LEVELS, true) ? $p : 'aggregate';
    }

    /** USD -> EUR for the cost estimate shown to the owner (the provider bills in USD). */
    public function usdToEur(): float
    {
        $v = $this->config->get('assistantUsdToEur');

        return is_numeric($v) && $v > 0.1 && $v < 5 ? (float) $v : 0.92;
    }

    public function businessName(): string
    {
        return trim((string) ($this->config->get('productName') ?: $this->config->get('applicationName') ?: ''));
    }

    public function timeZone(): string
    {
        return (string) ($this->config->get('timeZone') ?: 'UTC');
    }

    public function language(): string
    {
        return (string) ($this->config->get('language') ?: 'lv_LV');
    }
}
