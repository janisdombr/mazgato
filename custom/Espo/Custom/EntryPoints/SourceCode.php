<?php

namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Utils\Config;

/**
 * AGPL section 13: every user who talks to this installation over the network must be offered
 * the Corresponding Source of the version that is running. The operator publishes it somewhere and
 * enters the address in Settings -> `sourceCodeUrl` (Administration -> Brand). This public GET
 * entry point is the one stable address the UI links to (footer, About, login screen):
 *
 *   ?entryPoint=sourceCode            302 -> the configured address (404 when none is configured)
 *   ?entryPoint=sourceCode&format=json  {"url": "..."}  (the static link script uses this)
 *
 * Only a validated http(s) URL ever leaves here. See docs/AGPL.md.
 *
 * @noinspection PhpUnused
 */
class SourceCode implements EntryPoint
{
    use NoAuth;

    public function __construct(private Config $config) {}

    public function run(Request $request, Response $response): void
    {
        $url = trim((string) $this->config->get('sourceCodeUrl', ''));
        $ok = (bool) preg_match('#^https?://[^\s<>"\']+$#i', $url);
        $response->setHeader('Cache-Control', 'no-cache');

        if ($request->getQueryParam('format') === 'json') {
            $response->setHeader('Content-Type', 'application/json; charset=utf-8');
            $response->writeBody((string) json_encode(['url' => $ok ? $url : '', 'lang' => substr((string) $this->config->get('language', 'en_US'), 0, 2)]));

            return;
        }

        if (!$ok) {
            $response->setStatus(404);
            $response->setHeader('Content-Type', 'text/plain; charset=utf-8');
            $response->writeBody("The source code address has not been configured on this installation.\n");

            return;
        }

        $response->setStatus(302);
        $response->setHeader('Location', $url);
    }
}
