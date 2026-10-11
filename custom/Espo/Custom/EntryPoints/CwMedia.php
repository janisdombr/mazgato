<?php

namespace Espo\Custom\EntryPoints;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFoundSilent;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Custom\Tools\Anpr\Media;
use Espo\Custom\Tools\Anpr\MediaLog;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * The ONLY way the camera pipeline's pictures leave the CRM (they live in `data/camera-media`, see Tools/Anpr/Media):
 *
 *   ?entryPoint=cwMedia&type=plateEvent&id=<PlateEvent id>     the entrance snapshot
 *   ?entryPoint=cwMedia&type=command&id=<CameraCommand id>     a frame fetched on demand
 *   ?entryPoint=cwMedia&type=orderPhoto&id=<OrderPhoto id>     a camera photo of an order
 *
 * Needs a login (entry points are authenticated unless marked otherwise), the module flag, and
 * the record's own ACL; every look is written to the access journal (`MediaAccess`). Nothing is
 * cached by browsers or proxies. The file is served inline as an image with a locked-down CSP.
 */
class CwMedia implements EntryPoint
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private Modules $modules,
        private MediaLog $mediaLog,
        private Media $media,
        private FileStorageManager $files
    ) {}

    public function run(Request $request, Response $response): void
    {
        $type = (string) $request->getQueryParam('type');
        $id = (string) $request->getQueryParam('id');

        if ($id === '' || !in_array($type, ['plateEvent', 'command', 'orderPhoto'], true)) {
            throw new BadRequest('type and id are required.');
        }

        // [camera-store key | null, attachment id | null, journal ref, journal kind]
        [$key, $attachmentId, $ref, $kind] = match ($type) {
            'plateEvent' => $this->plateEvent($id),
            'command' => $this->command($id),
            'orderPhoto' => $this->orderPhoto($id),
        };

        if ($key !== null) {
            $bytes = $this->media->contents($key);

            if ($bytes === null) {
                throw new NotFoundSilent('No picture.');
            }
        } else {
            $attachment = $attachmentId ? $this->entityManager->getEntityById('Attachment', $attachmentId) : null;

            if (!$attachment) {
                throw new NotFoundSilent('No picture.');
            }
        }

        $ref['ip'] = $request->getHeader('X-Real-IP') ?: $request->getServerParam('REMOTE_ADDR');
        $this->mediaLog->record($kind, $this->user->getId(), $ref);

        if ($key !== null) {
            $size = (string) strlen($bytes);
            $response->writeBody($bytes);
        } else {
            $stream = $this->files->getStream($attachment);
            $size = (string) ($stream->getSize() ?? $this->files->getSize($attachment));
            $response->setBody($stream);
        }

        $response
            ->setHeader('Content-Type', 'image/jpeg')
            ->setHeader('Content-Disposition', 'inline;filename="frame.jpg"')
            ->setHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'none'; script-src 'none'; object-src 'none';")
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Referrer-Policy', 'no-referrer')
            ->setHeader('Content-Length', $size);
    }

    /** @return array{0: ?string, 1: ?string, 2: array<string, mixed>, 3: string} */
    private function plateEvent(string $id): array
    {
        if (!$this->modules->moduleEnabled(Modules::ANPR)) {
            throw new Forbidden('Module disabled.');
        }

        $e = $this->entityManager->getEntityById('PlateEvent', $id);

        if (!$e) {
            throw new NotFoundSilent('Not found.');
        }

        if (!$this->acl->checkEntityRead($e)) {
            throw new Forbidden('No access.');
        }

        return [$e->get('snapshotKey') ?: '-', null, ['plateEventId' => $e->getId()], 'PlateEvent'];
    }

    /** @return array{0: ?string, 1: ?string, 2: array<string, mixed>, 3: string} */
    private function command(string $id): array
    {
        if (!$this->modules->moduleEnabled(Modules::CAMERAS)) {
            throw new Forbidden('Module disabled.');
        }

        $c = $this->entityManager->getEntityById('CameraCommand', $id);
        $camera = $c && $c->get('cameraId') ? $this->entityManager->getEntityById('Camera', (string) $c->get('cameraId')) : null;

        if (!$c || !$camera) {
            throw new NotFoundSilent('Not found.');
        }

        if (!$this->acl->checkEntityRead($camera)) {
            throw new Forbidden('No access.');
        }

        return [$c->get('fileKey') ?: '-', null, ['cameraId' => $camera->getId()], 'Snapshot'];
    }

    /** @return array{0: ?string, 1: ?string, 2: array<string, mixed>, 3: string} */
    private function orderPhoto(string $id): array
    {
        $p = $this->entityManager->getEntityById('OrderPhoto', $id);

        if (!$p) {
            throw new NotFoundSilent('Not found.');
        }

        if (!$this->acl->checkEntityRead($p)) {
            throw new Forbidden('No access.');
        }

        return [null, $p->get('fileId'), ['orderPhotoId' => $p->getId()], 'OrderPhoto'];
    }
}
