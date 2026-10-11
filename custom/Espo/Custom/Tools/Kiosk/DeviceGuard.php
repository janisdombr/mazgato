<?php

namespace Espo\Custom\Tools\Kiosk;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * What a tablet IS: an API user that carries the "Kiosk" role. Everything the device may
 * do goes through `Kiosk/action/*`, which checks the worker itself — the role is empty
 * on purpose (every entity scope = no), so the device's key opens nothing else.
 *
 * Controllers that predate the kiosk and do no ACL of their own (`CarWash`) call
 * `denyDevice()` in their constructor, so a stolen tablet key cannot read statistics.
 */
class DeviceGuard
{
    public const ROLE_NAME = 'Kiosk';

    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private EntityManager $entityManager)
    {}

    public function isDevice(User $user): bool
    {
        if (!$user->isApi()) {
            return false;
        }

        $id = $user->getId();

        if (!array_key_exists($id, $this->cache)) {
            $this->cache[$id] = $this->entityManager
                ->getRDBRepository(User::ENTITY_TYPE)
                ->getRelation($user, 'roles')
                ->where(['name' => self::ROLE_NAME])
                ->count() > 0;
        }

        return $this->cache[$id];
    }

    /**
     * @throws Forbidden
     */
    public function denyDevice(User $user): void
    {
        if ($this->isDevice($user)) {
            throw new Forbidden('A kiosk device may only use the Kiosk endpoints.');
        }
    }
}
