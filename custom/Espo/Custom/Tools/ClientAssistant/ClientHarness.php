<?php

namespace Espo\Custom\Tools\ClientAssistant;

/**
 * Test support for the public chat, alive only while the shared `assistantTestMode` is on
 * (bin/command assistant-test-mode on --provider=scripted): the script ScriptedModel plays and
 * the requests it received. Its own files, so a staff-assistant test running at the same time
 * is not disturbed. Under data/, never web-served.
 */
class ClientHarness
{
    private function dir(): string
    {
        $d = dirname(__DIR__, 5) . '/data/assistant-test-client';

        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }

        return $d;
    }

    /** @param array<int, array<string, mixed>> $script */
    public function setScript(array $script): void
    {
        file_put_contents($this->dir() . '/script.json', json_encode($script));
        @unlink($this->dir() . '/calls.json');
    }

    /** @return array<int, array<string, mixed>> */
    public function script(): array
    {
        $f = $this->dir() . '/script.json';

        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    /** @param array<string, mixed> $call */
    public function record(array $call): void
    {
        $f = $this->dir() . '/calls.json';
        $all = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $all[] = $call;
        file_put_contents($f, json_encode(array_slice($all, -20), JSON_UNESCAPED_UNICODE));
    }

    /** @return array<int, array<string, mixed>> */
    public function calls(): array
    {
        $f = $this->dir() . '/calls.json';

        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    public function clear(): void
    {
        @unlink($this->dir() . '/script.json');
        @unlink($this->dir() . '/calls.json');
    }
}
