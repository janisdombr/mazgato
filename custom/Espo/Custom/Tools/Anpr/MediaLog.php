<?php

namespace Espo\Custom\Tools\Anpr;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * "Who looked at which picture" - the access journal the privacy guide asks for. One
 * `MediaAccess` row per (user, target) per `QUIET_SECONDS`, so a board that re-draws every few
 * seconds does not flood it. Append-only (`Hooks/MediaAccess/Immutable`); old rows are purged
 * by `anprAccessLogDays`.
 */
class MediaLog
{
    private const QUIET_SECONDS = 600;

    public function __construct(private EntityManager $entityManager)
    {}

    /**
     * @param array{plateEventId?: ?string, orderPhotoId?: ?string, cameraId?: ?string, detail?: ?string, ip?: ?string, always?: bool} $ref
     */
    public function record(string $kind, ?string $userId, array $ref = []): ?Entity
    {
        $where = ['kind' => $kind, 'userId' => $userId, 'detail' => isset($ref['detail']) ? substr((string) $ref['detail'], 0, 200) : null];

        foreach (['plateEventId', 'orderPhotoId', 'cameraId'] as $k) {
            $where[$k] = $ref[$k] ?? null;
        }

        if (empty($ref['always'])) {
            $recent = $this->entityManager->getRDBRepository('MediaAccess')
                ->where($where + ['createdAt>=' => gmdate('Y-m-d H:i:s', time() - self::QUIET_SECONDS), 'deleted' => false])
                ->count();

            if ($recent > 0) {
                return null;
            }
        }

        $user = $userId ? $this->entityManager->getEntityById('User', $userId) : null;

        return $this->entityManager->createEntity('MediaAccess', $where + [
            'name' => $kind . ' · ' . ($user ? ($user->get('userName') ?: $user->get('name')) : '-'),
            'ip' => $ref['ip'] ?? null,
        ], ['silent' => true]);
    }
}
