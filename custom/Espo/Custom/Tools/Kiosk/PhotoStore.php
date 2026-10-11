<?php

namespace Espo\Custom\Tools\Kiosk;

use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Validate, normalise and store an order photo.
 *
 * What comes in is untrusted bytes, so nothing is stored as received: the image is
 * decoded with GD (which rejects anything that is not really a picture), downscaled to
 * `MAX_SIDE`, rotated per its EXIF orientation, and RE-ENCODED as JPEG. That strips every
 * EXIF block (GPS of the tablet, device serials) and any payload hidden after the image
 * data. Shared by the kiosk and, later, the camera bridge (`source`).
 */
class PhotoStore
{
    public const KINDS_KIOSK = ['Before', 'After', 'Other'];

    public const MAX_RAW_BYTES = 8 * 1024 * 1024;
    public const MAX_SIDE = 1600;
    public const MAX_PIXELS = 25_000_000;
    public const MAX_PER_ORDER = 30;
    public const JPEG_QUALITY = 82;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function retentionDays(): int
    {
        return max(1, (int) ($this->config->get('photoRetentionDays') ?: 30));
    }

    /**
     * @param string $payload base64 (optionally a data: URL)
     * @throws Deny
     */
    public function storeFromBase64(
        Entity $order,
        string $kind,
        string $source,
        string $payload,
        ?Entity $employee,
        ?string $takenAt = null
    ): Entity {
        $count = $this->entityManager->getRDBRepository('OrderPhoto')
            ->where(['washOrderId' => $order->getId(), 'deleted' => false])
            ->count();

        if ($count >= self::MAX_PER_ORDER) {
            throw new Deny('photo_limit', 409, ['max' => self::MAX_PER_ORDER]);
        }

        $jpeg = $this->jpegFromBase64($payload);

        $attachment = $this->entityManager->getNewEntity('Attachment');
        $attachment->set([
            'name' => strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $order->get('name'))) . '-' . strtolower($kind) . '.jpg',
            'type' => 'image/jpeg',
            'role' => 'Attachment',
            'field' => 'file',
            'relatedType' => 'OrderPhoto',
            'contents' => $jpeg,
        ]);
        $this->entityManager->saveEntity($attachment);

        $taken = $takenAt && strtotime($takenAt) && abs(strtotime($takenAt) - time()) < 2 * 86400
            ? gmdate('Y-m-d H:i:s', strtotime($takenAt))
            : gmdate('Y-m-d H:i:s');

        return $this->entityManager->createEntity('OrderPhoto', [
            'name' => $kind . ' · ' . $order->get('name'),
            'washOrderId' => $order->getId(),
            'kind' => $kind,
            'source' => $source,
            'fileId' => $attachment->getId(),
            'employeeId' => $employee ? $employee->getId() : null,
            'takenAt' => $taken,
        ]);
    }

    /**
     * The validated, downscaled, re-encoded JPEG for an untrusted base64 / data-URL picture.
     * Shared with the camera pipeline (plate-event snapshots, on-demand frames): the same gate
     * for every image that enters the CRM from outside.
     *
     * @throws Deny
     */
    public function jpegFromBase64(string $payload): string
    {
        return $this->normalise($this->decode($payload));
    }

    /**
     * @throws Deny
     */
    private function decode(string $payload): string
    {
        if (str_starts_with($payload, 'data:')) {
            $comma = strpos($payload, ',');
            $payload = $comma === false ? '' : substr($payload, $comma + 1);
        }

        // Cheap size gate before allocating the decoded copy.
        if (strlen($payload) > (int) (self::MAX_RAW_BYTES * 4 / 3) + 8) {
            throw new Deny('photo_too_large', 413, ['max' => self::MAX_RAW_BYTES]);
        }

        $raw = base64_decode(preg_replace('/\s+/', '', $payload) ?? '', true);

        if ($raw === false || $raw === '') {
            throw new Deny('photo_invalid', 422);
        }

        if (strlen($raw) > self::MAX_RAW_BYTES) {
            throw new Deny('photo_too_large', 413, ['max' => self::MAX_RAW_BYTES]);
        }

        return $raw;
    }

    /**
     * @throws Deny
     */
    private function normalise(string $raw): string
    {
        $info = @getimagesizefromstring($raw);

        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new Deny('photo_type', 415);
        }

        [$w, $h] = $info;

        if ($w < 32 || $h < 32 || $w * $h > self::MAX_PIXELS) {
            throw new Deny('photo_dimensions', 422);
        }

        $img = @imagecreatefromstring($raw);

        if (!$img) {
            throw new Deny('photo_invalid', 422);
        }

        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $img = $this->orient($img, $raw);
        }

        $w = imagesx($img);
        $h = imagesy($img);

        if (max($w, $h) > self::MAX_SIDE) {
            $scaled = $w >= $h
                ? imagescale($img, self::MAX_SIDE, -1, IMG_BILINEAR_FIXED)
                : imagescale($img, (int) round($w * self::MAX_SIDE / $h), self::MAX_SIDE, IMG_BILINEAR_FIXED);

            if ($scaled) {
                $img = $scaled;
            }
        }

        // PNG/WebP may carry alpha: flatten onto white, JPEG has none.
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));

        ob_start();
        imagejpeg($flat, null, self::JPEG_QUALITY);
        $out = (string) ob_get_clean();

        return $out;
    }

    private function orient(\GdImage $img, string $raw): \GdImage
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $raw);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle) {
            $rotated = imagerotate($img, $angle, 0);

            if ($rotated) {
                return $rotated;
            }
        }

        return $img;
    }
}
