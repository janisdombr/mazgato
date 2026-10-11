<?php

namespace Espo\Custom\Tools\Brand;

use Espo\Core\Utils\Config;

/**
 * The CLIENT's identity — name, document header, footer, signature, accent colour — read
 * from Settings (Administration → Brand), never from code. The product ships to other car washes;
 * a second wash changes these values, not files.
 *
 * Printed documents are always Latvian and carry no product name: the header is whatever the
 * client typed in `brandDocHeader` (falls back to the application name, then to nothing).
 */
class Brand
{
    public function __construct(private Config $config) {}

    private function str(string $key): string
    {
        return trim((string) $this->config->get($key, ''));
    }

    /** Legal / trade name for documents. */
    public function docName(): string
    {
        return $this->str('brandDocHeader') ?: $this->str('applicationName');
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    /** Centered document header: name + one line of address / reg. no. / PVN no. */
    public function headerHtml(): string
    {
        $name = $this->docName();

        if ($name === '') {
            return '';
        }

        $bits = array_filter([
            $this->str('brandDocAddress'),
            $this->str('brandDocRegNo') !== '' ? 'Reģ. Nr. ' . $this->str('brandDocRegNo') : '',
            $this->str('brandDocPvnNo') !== '' ? 'PVN Nr. ' . $this->str('brandDocPvnNo') : '',
        ]);

        return '<h2 style="margin:0">' . $this->esc($name) . '</h2>' .
            ($bits ? '<div style="color:#666; font-size:11px">' . $this->esc(implode(' · ', $bits)) . '</div>' : '');
    }

    /** Thank-you line under the totals; empty when the client has none. */
    public function footerHtml(): string
    {
        $t = $this->str('brandDocFooter');

        return $t === '' ? '' : '<p style="text-align:center; color:#666">' . $this->esc($t) . '</p>';
    }

    /** Plain-text sign-off for outgoing mail. */
    public function emailSignature(): string
    {
        return $this->str('brandEmailSignature') ?: $this->docName();
    }

    /** #rrggbb or '' (invalid values are ignored, never echoed into CSS). */
    public function color(): string
    {
        $c = $this->str('brandColor');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '';
    }

    /** Black or white, whichever reads better on the brand colour (WCAG luminance). */
    public function inkOn(string $hex): string
    {
        $c = ltrim($hex, '#');
        $ch = array_map(function ($i) use ($c) {
            $v = hexdec(substr($c, $i, 2)) / 255;

            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }, [0, 2, 4]);

        $l = 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];

        // contrast with white = 1.05/(l+0.05); with black = (l+0.05)/0.05
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.05) ? '#ffffff' : '#000000';
    }
}
