<?php

namespace Espo\Custom\Tools\Booking;

/**
 * File-based fixed-window limiter for the public booking endpoints (no login, so no user to
 * key on). One small file per (bucket, IP hash) under data/cache/booking-rate/. Old files are
 * swept opportunistically. The IP is hashed: no raw addresses are stored.
 */
class RateLimiter
{
    private const DIR = 'data/cache/booking-rate';

    private function dir(): string
    {
        $d = (defined('BASE_PATH') ? BASE_PATH : '/var/www/html') . '/' . self::DIR;

        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }

        return $d;
    }

    /** @return bool true = allowed (and counted) */
    public function hit(string $bucket, string $ip, int $limit, int $windowSeconds): bool
    {
        $file = $this->dir() . '/' . $bucket . '-' . substr(hash('sha256', $ip), 0, 24);
        $now = time();
        $times = [];

        if (is_file($file)) {
            foreach (explode("\n", (string) file_get_contents($file)) as $t) {
                if ((int) $t > $now - $windowSeconds) {
                    $times[] = (int) $t;
                }
            }
        }

        if (count($times) >= $limit) {
            return false;
        }

        $times[] = $now;
        file_put_contents($file, implode("\n", $times), LOCK_EX);

        if (random_int(1, 50) === 1) {
            foreach (glob($this->dir() . '/*') ?: [] as $f) {
                if (filemtime($f) < $now - 86400) {
                    @unlink($f);
                }
            }
        }

        return true;
    }

    /** @return bool true = the bucket is already full (nothing is counted) */
    public function exceeded(string $bucket, string $ip, int $limit, int $windowSeconds): bool
    {
        $file = $this->dir() . '/' . $bucket . '-' . substr(hash('sha256', $ip), 0, 24);

        if (!is_file($file)) {
            return false;
        }

        $now = time();
        $n = 0;

        foreach (explode("\n", (string) file_get_contents($file)) as $t) {
            if ((int) $t > $now - $windowSeconds) {
                $n++;
            }
        }

        return $n >= $limit;
    }

    public function reset(): void
    {
        foreach (glob($this->dir() . '/*') ?: [] as $f) {
            @unlink($f);
        }
    }
}
