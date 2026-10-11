<?php

namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Custom\Tools\Brand\Brand;

/**
 * Public stylesheet carrying the client's brand: `?entryPoint=brandCss`. Linked from
 * app/client.json, so it is on every page including the login screen (before any user
 * exists). Only the validated #rrggbb accent leaves here — no other setting is exposed.
 * It sets --cw-brand (+ its readable ink) and nothing else; the main button stays the
 * product accent, so a low-contrast brand colour can never make a button unreadable.
 * It does NOT hide or alter the stock "© EspoCRM, Inc." notice anywhere (AGPL section 7(b): the
 * Appropriate Legal Notices must keep the display of the "EspoCRM" word - docs/AGPL.md).
 *
 * @noinspection PhpUnused
 */
class BrandCss implements EntryPoint
{
    use NoAuth;

    public function __construct(private Brand $brand) {}

    public function run(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'text/css; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-cache');

        $color = $this->brand->color();
        $css = '/* client brand */' . "\n";

        if ($color !== '') {
            $css .= ':root:root{--cw-brand:' . $color . ';--cw-brand-ink:' . $this->brand->inkOn($color) . ';}' . "\n";
        }

        $response->writeBody($css);
    }
}
