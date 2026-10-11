<?php

namespace Espo\Custom\Tools\Kiosk;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Util;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Provisioning of tablets: "issue a device" = an API user (X-Api-Key) with the Kiosk role.
 * The role is created empty — no entity scope is granted (this Espo applies strict ACL
 * defaults, so a scope that is not listed is denied) — and every kiosk read/write goes
 * through `Kiosk/action/*`, which enforces the rules itself. Compromised tablet = revoke
 * one user; nothing else is affected.
 */
class DeviceAdmin
{
    public function __construct(
        private EntityManager $entityManager,
        private SessionManager $sessions
    ) {}

    public function ensureRole(): Entity
    {
        $role = $this->entityManager->getRDBRepository('Role')
            ->where(['name' => DeviceGuard::ROLE_NAME])
            ->findOne();

        if ($role) {
            return $role;
        }

        return $this->entityManager->createEntity('Role', [
            'name' => DeviceGuard::ROLE_NAME,
            'info' => 'Worker tablet. Grants nothing: the device acts only through the Kiosk endpoints.',
            'data' => (object) [],
            'fieldData' => (object) [],
            'assignmentPermission' => 'no',
            'userPermission' => 'no',
            'messagePermission' => 'no',
            'portalPermission' => 'no',
            'groupEmailAccountPermission' => 'no',
            'exportPermission' => 'no',
            'massUpdatePermission' => 'no',
            'dataPrivacyPermission' => 'no',
            'followerManagementPermission' => 'no',
            'auditPermission' => 'no',
            'mentionPermission' => 'no',
            'userCalendarPermission' => 'no',
        ]);
    }

    /**
     * @return array{id: string, userName: string, name: string, apiKey: string}
     * @throws BadRequest
     */
    public function issue(string $name): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw new BadRequest("Field 'name' is required (up to 60 characters).");
        }

        $role = $this->ensureRole();

        $slug = trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name) ?? ''), '-');
        $userName = 'kiosk-' . ($slug !== '' ? substr($slug, 0, 24) : 'tablet') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);

        $user = $this->entityManager->getNewEntity('User');
        $user->set([
            'type' => 'api',
            'userName' => $userName,
            'lastName' => $name,
            'authMethod' => 'ApiKey',
            'isActive' => true,
            'apiKey' => Util::generateApiKey(),
        ]);
        $this->entityManager->saveEntity($user);

        // Espo copies userName into lastName for an API user on create; the label the admin
        // typed ("Bay 1") is what the list should show, so put it back. Later saves keep it
        // (the copy only happens when userName changes).
        $this->entityManager->getQueryExecutor()->execute(
            $this->entityManager->getQueryBuilder()->update()->in('User')->set(['lastName' => $name])->where(['id' => $user->getId()])->build()
        );

        $this->entityManager->getRDBRepository('User')->getRelation($user, 'roles')->relate($role);

        return [
            'id' => $user->getId(),
            'userName' => $userName,
            'name' => $name,
            'apiKey' => (string) $user->get('apiKey'),
        ];
    }

    /**
     * @throws NotFound
     */
    public function revoke(string $deviceId): int
    {
        $user = $this->device($deviceId);

        $user->set('isActive', false);
        $this->entityManager->saveEntity($user);

        return $this->sessions->endAllForDevice($deviceId, 'Revoked');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function devices(): array
    {
        $role = $this->entityManager->getRDBRepository('Role')
            ->where(['name' => DeviceGuard::ROLE_NAME])
            ->findOne();

        if (!$role) {
            return [];
        }

        $users = $this->entityManager->getRDBRepository('Role')
            ->getRelation($role, 'users')
            ->where(['type' => 'api'])
            ->order('createdAt', 'DESC')
            ->find();

        $out = [];

        foreach ($users as $user) {
            $last = $this->sessions->lastSeen($user->getId());

            $out[] = [
                'id' => $user->getId(),
                'name' => (string) ($user->get('lastName') ?: $user->get('userName')),
                'userName' => (string) $user->get('userName'),
                'active' => (bool) $user->get('isActive'),
                'createdAt' => $user->get('createdAt'),
                'lastSeenAt' => $last,
            ];
        }

        return $out;
    }

    /**
     * @throws NotFound
     */
    private function device(string $deviceId): User
    {
        $user = $this->entityManager->getEntityById('User', $deviceId);

        /** @var ?User $user */
        if (!$user || !$user->isApi()) {
            throw new NotFound('Device not found.');
        }

        $role = $this->entityManager->getRDBRepository('User')
            ->getRelation($user, 'roles')
            ->where(['name' => DeviceGuard::ROLE_NAME])
            ->count();

        if ($role === 0) {
            throw new NotFound('Device not found.');
        }

        return $user;
    }
}
