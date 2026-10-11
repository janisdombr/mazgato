<?php

namespace Espo\Custom\Tools\Anpr;

use Espo\Core\Utils\Config;

/**
 * Every tunable of the plate-recognition / camera modules in one place, with its default and
 * its clamp. All of them are Settings (Administration -> Car wash -> Plates and cameras);
 * nothing is hard-coded in the endpoints.
 */
class Settings
{
    public function __construct(private Config $config)
    {}

    /** Auto-create an order from a high-confidence, known car. OFF by default. */
    public function autoCreate(): bool
    {
        return (bool) $this->config->get('anprAutoCreate');
    }

    /** Below this the event is always a "could not read it" card. */
    public function minConfidence(): float
    {
        return $this->unit('anprMinConfidence', 0.6);
    }

    /** At or above this (and only then) the system may act without a question. */
    public function highConfidence(): float
    {
        return max($this->minConfidence(), $this->unit('anprHighConfidence', 0.9));
    }

    /** The same plate on the same camera inside this window is one event, not many. */
    public function dedupSeconds(): int
    {
        return $this->int('anprDedupSeconds', 300, 5, 3600);
    }

    /** Snapshots are deleted after this many days (GDPR guide: 30; check with a lawyer). */
    public function snapshotDays(): int
    {
        return $this->int('anprRetentionDays', 30, 1, 3650);
    }

    /** Event rows (plate, time, camera — no picture) are deleted after this many days (guide: 90). */
    public function eventDays(): int
    {
        return $this->int('anprEventRetentionDays', 90, 1, 3650);
    }

    /** Who-viewed-what log retention. */
    public function accessLogDays(): int
    {
        return $this->int('anprAccessLogDays', 180, 30, 3650);
    }

    public function rateLimitPerMinute(): int
    {
        return $this->int('anprRateLimitPerMinute', 120, 10, 6000);
    }

    /** An offline edge node may deliver old events; older than this is refused. */
    public function maxEventAgeHours(): int
    {
        return $this->int('anprMaxEventAgeHours', 48, 1, 24 * 14);
    }

    public function pollSeconds(): int
    {
        return $this->int('anprPollSeconds', 5, 2, 60);
    }

    public function alertSound(): bool
    {
        $v = $this->config->get('anprAlertSound');

        return $v === null ? true : (bool) $v;
    }

    /** How long a snapshot-on-demand frame is kept. */
    public function frameKeepHours(): int
    {
        return $this->int('camerasFrameKeepHours', 24, 1, 24 * 14);
    }

    /** Where the reverse proxy publishes the go2rtc / Frigate viewer (path or absolute URL). */
    public function viewBase(): string
    {
        $v = trim((string) ($this->config->get('camerasViewBase') ?: '/cam'));

        return rtrim($v, '/') ?: '/cam';
    }

    /** Seconds without a heartbeat after which a node counts as offline. */
    public function nodeOfflineSeconds(): int
    {
        return 90;
    }

    private function unit(string $key, float $default): float
    {
        $v = $this->config->get($key);

        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
        }

        return min(1.0, max(0.0, (float) $v));
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $v = $this->config->get($key);

        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
        }

        return min($max, max($min, (int) $v));
    }
}
