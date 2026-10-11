<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Roles\Overview;
use Espo\Custom\Tools\Roles\RoleSeeder;
use Espo\Entities\User;
use stdClass;

/**
 * Access/action/*   (administrator only, never reachable with a tablet key)
 *
 *  overview   the "Roles and access" page: per level (owner, manager, operator, other roles) what it may and may not do,
 *             computed from the real Role rows, the users in each level, the tablet/worker counts. Read only.
 *  seedRoles  create the Manager / Operator roles that are missing (never touches an existing role) - same as `bin/command seed-roles`
 */
class Access
{
    public function __construct(private User $user, private Overview $overview, private RoleSeeder $seeder, private Config $config, DeviceGuard $deviceGuard)
    {
        $deviceGuard->denyDevice($user);

        if (!$user->isAdmin()) {
            throw new Forbidden();
        }
    }

    public function postActionOverview(Request $request): stdClass
    {
        return json_decode((string) json_encode($this->overview->build()));
    }

    public function postActionSeedRoles(Request $request): stdClass
    {
        $lang = (string) ($this->config->get('language') ?: 'lv_LV');

        return (object) ['result' => (object) $this->seeder->seed($lang, false)];
    }
}
