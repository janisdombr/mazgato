<?php

namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Custom\Tools\Anpr\Settings;
use Espo\Custom\Tools\Anpr\StreamToken;
use Espo\Custom\Tools\Licensing\Modules;

/**
 * The reverse proxy's gate for the live viewer (nginx `auth_request`):
 *
 *   GET /?entryPoint=camAuth        headers from nginx: X-Original-URI, Cookie (cwcam=<pass>)
 *   204  = let the request through to go2rtc / Frigate;   401 = refuse.
 *
 * The pass is the stateless HMAC token from `Cameras/action/streamToken` (5 minutes, one
 * stream). Besides a valid pass, the original URI must be (a) one of the viewer's own static
 * files, or (b) a request whose `src=` is exactly the stream named in the pass - so a pass for
 * the entrance camera cannot open `api/streams` (the list of all cameras) or another stream.
 * Public (no session) because nginx cannot forward one; the pass IS the authorisation.
 */
class CamAuth implements EntryPoint
{
    use NoAuth;

    /** Static files of the go2rtc viewer - harmless without a stream. */
    private const STATIC_FILES = ['stream.html', 'video-stream.js', 'video-rtc.js', 'favicon.ico'];

    /** go2rtc endpoints that serve ONE stream chosen by `src=`. */
    private const STREAM_ENDPOINTS = ['api/ws', 'api/stream.mp4', 'api/frame.jpeg', 'api/frame.mp4', 'api/stream.m3u8', 'api/hls/playlist.m3u8'];

    public function __construct(private StreamToken $tokens, private Modules $modules, private Settings $settings)
    {}

    public function run(Request $request, Response $response): void
    {
        $response->setHeader('Cache-Control', 'no-store');

        if (!$this->modules->moduleEnabled(Modules::CAMERAS)) {
            $response->setStatus(401);

            return;
        }

        $pass = $this->tokens->verify($this->tokenOf($request));

        if (!$pass || !$this->allowed((string) $request->getHeader('X-Original-URI'), $pass['s'])) {
            $response->setStatus(401);

            return;
        }

        $response->setStatus(204);
    }

    private function tokenOf(Request $request): ?string
    {
        $q = $request->getQueryParam('token');

        if ($q) {
            return $q;
        }

        foreach (explode(';', (string) $request->getHeader('Cookie')) as $part) {
            $kv = explode('=', trim($part), 2);

            if (count($kv) === 2 && $kv[0] === StreamToken::COOKIE) {
                return $kv[1];
            }
        }

        return null;
    }

    private function allowed(string $originalUri, string $stream): bool
    {
        if ($originalUri === '') {
            return false;
        }

        $path = (string) parse_url($originalUri, PHP_URL_PATH);
        parse_str((string) parse_url($originalUri, PHP_URL_QUERY), $query);

        // strip the proxy prefix (Settings camerasViewBase, default /cam): "/cam/stream.html" -> "stream.html"
        $prefix = rtrim((string) (parse_url($this->settings->viewBase(), PHP_URL_PATH) ?: '/cam'), '/') . '/';

        if (!str_starts_with($path, $prefix)) {
            return false;
        }

        $rel = substr($path, strlen($prefix));

        if (str_contains($rel, '..')) {
            return false;
        }

        if (in_array($rel, self::STATIC_FILES, true)) {
            return true;
        }

        return in_array($rel, self::STREAM_ENDPOINTS, true) && ($query['src'] ?? null) === $stream;
    }
}
