<?php

namespace Espo\Custom\Tools\Anpr;

use Espo\Custom\Tools\Kiosk\Deny;
use Espo\Custom\Tools\Kiosk\PhotoStore;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Commands for an edge node. The CRM cannot reach into the wash network, so "give me a frame
 * from the entry camera now" is a row in a queue that the node picks up by OUTBOUND polling
 * (`Anpr/action/commands`), executes locally and answers with `commandResult`:
 *
 *   Queued -> Leased (handed to the node; re-offered if it does not answer within LEASE_SECONDS)
 *          -> Done | Failed;  anything unanswered after EXPIRE_SECONDS -> Expired.
 *
 * Types: Snapshot (one JPEG), Clips (a list of recordings around a time, from the NVR).
 */
class Commands
{
    public const LEASE_SECONDS = 20;
    public const EXPIRE_SECONDS = 120;

    public function __construct(
        private EntityManager $entityManager,
        private Media $media,
        private PhotoStore $photos
    ) {}

    /** @param array<string, mixed> $params */
    public function enqueue(Entity $camera, string $type, ?string $userId, array $params = []): Entity
    {
        return $this->entityManager->createEntity('CameraCommand', [
            'name' => $type . ' · ' . $camera->get('code'),
            'type' => $type,
            'status' => 'Queued',
            'cameraId' => $camera->getId(),
            'edgeNodeId' => $camera->get('edgeNodeId'),
            'requestedById' => $userId,
            'requestedAt' => gmdate('Y-m-d H:i:s'),
            'params' => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        ], ['silent' => true]);
    }

    /**
     * What the node should do now (marks them Leased).
     *
     * @return array<int, array<string, mixed>>
     */
    public function lease(Entity $node, int $max = 5): array
    {
        $this->expireOld();

        $rows = $this->entityManager->getRDBRepository('CameraCommand')
            ->where(['edgeNodeId' => $node->getId(), 'deleted' => false, 'status' => ['Queued', 'Leased']])
            ->order('requestedAt', 'ASC')
            ->limit(0, $max)
            ->find();

        $out = [];
        $now = time();

        foreach ($rows as $c) {
            if ($c->get('status') === 'Leased' && strtotime($c->get('leasedAt') . ' UTC') > $now - self::LEASE_SECONDS) {
                continue;
            }

            $camera = $this->entityManager->getEntityById('Camera', (string) $c->get('cameraId'));

            if (!$camera) {
                continue;
            }

            $c->set(['status' => 'Leased', 'leasedAt' => gmdate('Y-m-d H:i:s')]);
            $this->entityManager->saveEntity($c, ['silent' => true]);

            $out[] = [
                'id' => $c->getId(),
                'type' => (string) $c->get('type'),
                'camera' => (string) $camera->get('code'),
                'params' => $c->get('params') ? json_decode((string) $c->get('params'), true) : new \stdClass(),
            ];
        }

        return $out;
    }

    public function pendingCount(Entity $node): int
    {
        return $this->entityManager->getRDBRepository('CameraCommand')
            ->where(['edgeNodeId' => $node->getId(), 'deleted' => false, 'status' => ['Queued', 'Leased']])
            ->count();
    }

    /**
     * @throws Deny
     */
    public function complete(Entity $node, \stdClass $b): Entity
    {
        $id = (string) ($b->commandId ?? '');
        $c = $id !== '' ? $this->entityManager->getEntityById('CameraCommand', $id) : null;

        if (!$c || $c->get('edgeNodeId') !== $node->getId()) {
            throw new Deny('unknown_command', 404);
        }

        if (!in_array($c->get('status'), ['Queued', 'Leased'], true)) {
            return $c; // a late or repeated answer changes nothing
        }

        if (empty($b->ok)) {
            $c->set(['status' => 'Failed', 'doneAt' => gmdate('Y-m-d H:i:s'), 'error' => substr((string) ($b->error ?? 'failed'), 0, 190)]);
            $this->entityManager->saveEntity($c, ['silent' => true]);

            return $c;
        }

        if ($c->get('type') === 'Snapshot') {
            $jpeg = $this->photos->jpegFromBase64((string) ($b->snapshotBase64 ?? ''));
            $c->set(['fileKey' => $this->media->store($jpeg), 'hasFile' => true]);
        } else {
            $c->set(['result' => json_encode($this->clips($b->clips ?? []), JSON_UNESCAPED_UNICODE)]);
        }

        $c->set(['status' => 'Done', 'doneAt' => gmdate('Y-m-d H:i:s')]);
        $this->entityManager->saveEntity($c, ['silent' => true]);

        return $c;
    }

    public function expireOld(): void
    {
        $rows = $this->entityManager->getRDBRepository('CameraCommand')
            ->where(['deleted' => false, 'status' => ['Queued', 'Leased'], 'requestedAt<' => gmdate('Y-m-d H:i:s', time() - self::EXPIRE_SECONDS)])
            ->find();

        foreach ($rows as $c) {
            $c->set(['status' => 'Expired']);
            $this->entityManager->saveEntity($c, ['silent' => true]);
        }
    }

    /**
     * A clip list from the node: only plain strings, a relative path (no scheme, no host, no
     * credentials), bounded in number and length.
     *
     * @param mixed $clips
     * @return array<int, array<string, string>>
     */
    private function clips(mixed $clips): array
    {
        $out = [];

        if (!is_array($clips)) {
            return $out;
        }

        foreach (array_slice($clips, 0, 50) as $c) {
            $c = (array) $c;
            $path = (string) ($c['path'] ?? '');

            if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:|//|@#', $path)) {
                $path = '';
            }

            $out[] = [
                'start' => substr((string) ($c['start'] ?? ''), 0, 32),
                'end' => substr((string) ($c['end'] ?? ''), 0, 32),
                'label' => substr(strip_tags((string) ($c['label'] ?? '')), 0, 60),
                'path' => substr($path, 0, 200),
            ];
        }

        return $out;
    }
}
