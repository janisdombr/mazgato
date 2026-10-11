<?php

namespace Espo\Custom\Tools\Anpr;

/**
 * Pictures of the camera pipeline (event snapshots, on-demand frames) live in their OWN store,
 * `data/camera-media/<random key>.jpg` - not in the CRM's attachment table. Why: a stock
 * Attachment can be fetched by its id through the core download / image entry points by any
 * signed-in user, around the access journal; a file that is not an Attachment can only be served by
 * the `cwMedia` entry point, which checks the real ACL AND writes the journal. Nothing else knows
 * the path (`data/` is not web-served), the record holds only the random key (hidden from the API),
 * and deleting is real: the file is unlinked, there is no soft-deleted copy to forget.
 */
class Media
{
    private const DIR = 'data/camera-media';

    public function dir(): string
    {
        $d = (defined('BASE_PATH') ? BASE_PATH : '/var/www/html') . '/' . self::DIR;

        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }

        return $d;
    }

    public function path(?string $key): ?string
    {
        return $key && preg_match('/^[a-f0-9]{32}$/', $key) ? $this->dir() . '/' . $key . '.jpg' : null;
    }

    /** Stores the (already validated and re-encoded) JPEG, returns its random key. */
    public function store(string $jpeg): string
    {
        $key = bin2hex(random_bytes(16));
        $path = $this->path($key);
        $tmp = $path . '.tmp';

        if (file_put_contents($tmp, $jpeg, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException('Could not store the camera picture.');
        }

        @chmod($path, 0640);

        return $key;
    }

    public function contents(?string $key): ?string
    {
        $path = $this->path($key);

        if (!$path || !is_file($path)) {
            return null;
        }

        $bytes = file_get_contents($path);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    public function exists(?string $key): bool
    {
        $path = $this->path($key);

        return $path !== null && is_file($path);
    }

    public function destroy(?string $key): void
    {
        $path = $this->path($key);

        if ($path && is_file($path)) {
            @unlink($path);
        }
    }
}
