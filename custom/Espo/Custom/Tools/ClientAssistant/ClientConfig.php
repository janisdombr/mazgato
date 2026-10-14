<?php

namespace Espo\Custom\Tools\ClientAssistant;

use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Assistant\AssistantConfig;
use Espo\Custom\Tools\Assistant\Providers;
use Espo\Custom\Tools\Assistant\Secret;

/**
 * Settings of the PUBLIC (customer-facing) chat. It is an AssistantConfig, so the Loop and the
 * AnthropicModel run unchanged, but every bound is tighter and has its own hard ceiling that
 * the admin page can lower and never raise: the caller is an anonymous visitor, not staff.
 *
 * Shared with the staff assistant on purpose: the API key (`assistantApiKey`, internal, write-only)
 * and the test mode (`assistantTestMode`). The provider switch is separate (`clientAssistantProvider`),
 * so the public chat and the staff assistant are enabled independently.
 */
class ClientConfig extends AssistantConfig
{
    public const QUESTION_MAX = 400;
    public const HISTORY_TURNS = 4;
    public const HISTORY_MINUTES = 60;
    public const ANSWER_MAX = 1500;

    /** Hard ceilings (cannot be raised from the admin page). */
    public const STEPS_CEILING = 5;
    public const SECONDS_CEILING = 60;
    public const BUDGET_CEILING_EUR = 1000.0;

    public function __construct(private Config $src)
    {
        parent::__construct($src);
    }

    private function n(string $key, int $default, int $min, int $max): int
    {
        $v = $this->src->get($key);

        return $v === null || $v === '' || !is_numeric($v) ? $default : max($min, min($max, (int) $v));
    }

    /** off | a catalogue provider (Providers::CATALOG) | scripted (only while the shared test mode is on). */
    public function provider(): string
    {
        if ($this->testMode() && (string) $this->src->get('assistantProvider') === 'scripted') {
            return 'scripted';
        }

        $p = (string) $this->src->get('clientAssistantProvider', 'off');

        return Providers::has($p) ? $p : 'off';
    }

    /**
     * The chat's key: its own (`clientAssistantApiKey`, internal, write-only) - a different provider needs a different key.
     * Anthropic falls back to the staff assistant's shared key. In test mode the shared key is used for any provider,
     * so the tests need not touch a real key.
     */
    public function key(): Secret
    {
        $shared = parent::key();

        if ($this->testMode() && $shared->isSet()) {
            return $shared;
        }

        $own = trim((string) $this->src->get('clientAssistantApiKey', ''));

        if ($own !== '') {
            return new Secret($own);
        }

        return $this->provider() === 'anthropic' ? $shared : new Secret('');
    }

    public function isConfigured(): bool
    {
        $p = $this->provider();

        return $p === 'scripted' || (Providers::has($p) && $this->key()->isSet());
    }

    /**
     * The provider's fixed base URL. Only the test mode may point elsewhere (a mock server), so a stored value can
     * never redirect the key to an arbitrary host.
     */
    public function apiBase(): string
    {
        $b = rtrim((string) $this->src->get('assistantApiBase', ''), '/');

        if ($this->testMode() && preg_match('#^https?://[^\s]+$#', $b)) {
            return $b;
        }

        return Providers::get($this->provider())['base'];
    }

    private function modelOr(string $key, string $default): string
    {
        $v = trim((string) $this->src->get($key, ''));

        return Providers::validModel($this->provider(), $v) ? $v : $default;
    }

    // A public chat is a cheap FAQ + booking helper: the provider's small model by default.
    public function model(): string { return $this->modelOr('clientAssistantModel', Providers::get($this->provider())['model']); }

    public function fallbackModel(): string { return $this->modelOr('clientAssistantFallbackModel', Providers::get($this->provider())['model']); }

    public function effort(): string { return ''; }

    public function maxSteps(): int { return $this->n('clientAssistantMaxSteps', 4, 1, self::STEPS_CEILING); }

    public function turnSeconds(): int { return $this->n('clientAssistantTurnSeconds', 30, 5, self::SECONDS_CEILING); }

    public function turnInputTokens(): int { return $this->n('clientAssistantTurnTokens', 20000, 3000, 40000); }

    public function maxOutputTokens(): int { return $this->n('clientAssistantMaxOutputTokens', 500, 150, 1000); }

    /** At least 31 days: the monthly limits are sums over the journal. */
    public function retentionDays(): int { return $this->n('clientAssistantRetentionDays', 31, 31, 365); }

    public function perIpMinute(): int { return $this->n('clientAssistantPerIpMinute', 6, 1, 60); }

    public function perIpHour(): int { return $this->n('clientAssistantPerIpHour', 25, 1, 500); }

    public function perIpDay(): int { return $this->n('clientAssistantPerIpDay', 60, 1, 2000); }

    public function dayQuestions(): int { return $this->n('clientAssistantDayQuestions', 400, 1, 100000); }

    public function monthQuestions(): int { return $this->n('clientAssistantMonthQuestions', 4000, 1, 1000000); }

    public function monthTokens(): int { return $this->n('clientAssistantMonthTokens', 4000000, 10000, 1000000000); }

    /** Bookings one visitor (IP) may make through the chat per day; 0 = the chat may not book. */
    public function bookingsPerIpDay(): int { return $this->n('clientAssistantBookingsPerIpDay', 3, 0, 50); }

    /** The hard money ceiling: once this month's estimated cost reaches it the chat stops (EUR). */
    public function monthBudgetEur(): float
    {
        $v = $this->src->get('clientAssistantMonthBudgetEur');

        return is_numeric($v) && $v > 0 ? min(self::BUDGET_CEILING_EUR, (float) $v) : 10.0;
    }

    public function text(string $key, int $max): string
    {
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $this->src->get($key, ''));

        return trim(mb_substr($v, 0, $max));
    }

    public function siteUrl(): string
    {
        return rtrim((string) $this->src->get('siteUrl', ''), '/');
    }
}
