<?php

namespace Espo\Custom\Tools\Anpr;

/**
 * A synthetic "car at the entrance" picture with a readable plate - for the admin page's test
 * event, the demo and the tests. No real vehicle or person is ever in it.
 */
class TestImage
{
    public static function jpeg(string $plate, int $width = 640, int $height = 360): string
    {
        $img = imagecreatetruecolor($width, $height);

        // asphalt-and-wall gradient
        for ($y = 0; $y < $height; $y++) {
            $c = (int) (60 + 50 * $y / $height);
            imageline($img, 0, $y, $width, $y, imagecolorallocate($img, $c, $c + 4, $c + 10));
        }

        // car body block
        imagefilledrectangle($img, (int) ($width * 0.12), (int) ($height * 0.30), (int) ($width * 0.88), (int) ($height * 0.88), imagecolorallocate($img, 38, 52, 74));

        // the plate: white plate, blue EU strip, black text, scaled up from a small canvas
        $text = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $plate) ?? '', 0, 10));
        $small = imagecreatetruecolor(150, 34);
        imagefill($small, 0, 0, imagecolorallocate($small, 245, 245, 240));
        imagefilledrectangle($small, 0, 0, 12, 34, imagecolorallocate($small, 0, 51, 153));
        imagestring($small, 5, 20, 9, $text, imagecolorallocate($small, 10, 10, 10));
        $pw = (int) ($width * 0.46);
        $ph = (int) ($pw * 34 / 150);
        $px = (int) (($width - $pw) / 2);
        $py = (int) ($height * 0.62);
        imagecopyresized($img, $small, $px, $py, 0, 0, $pw, $ph, 150, 34);

        ob_start();
        imagejpeg($img, null, 85);

        return (string) ob_get_clean();
    }
}
