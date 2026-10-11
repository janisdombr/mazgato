<?php

namespace Espo\Custom\Tools\Roles;

use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The "Roles and access" page (#Admin/carwashRoles) as data: for each level of the car wash (owner / manager / operator /
 * worker tablet) WHAT IT MAY AND MAY NOT DO, computed from the REAL role rows in the database - not from a text - plus the
 * users in it. A hand edit of a seeded role therefore shows up on the page, together with a "differs from the preset" note.
 *
 * Pure read: it changes no right. Wages and the settings are administrator-only in code (docs/ROLES.md), which is stated here
 * as a fixed fact, not read from a role.
 */
final class Overview
{
    /**
     * key => rule. A rule is [ [scope, action, need], ... ] (all must hold), or a string:
     * 'admin' (administrator only, in code), 'revenue' (read all orders + the Revenue switch), 'export' (export permission),
     * 'accounting' (administrator, or revenue).
     * need: 'yes' = any level above "no", 'all' = level all.
     */
    public const CAPABILITIES = [
        'intake' => [['WashOrder', 'create', 'yes'], ['Vehicle', 'read', 'yes'], ['Customer', 'read', 'yes']],
        'ordersEdit' => [['WashOrder', 'read', 'all'], ['WashOrder', 'edit', 'all']],
        'ordersDelete' => [['WashOrder', 'delete', 'yes']],
        'customers' => [['Customer', 'create', 'yes'], ['Customer', 'edit', 'all']],
        'subscriptions' => [['Subscription', 'create', 'yes']],
        'shifts' => [['Shift', 'create', 'yes']],
        'gate' => [['PlateEvent', 'read', 'yes']],
        'catalogRead' => [['WashService', 'read', 'yes']],
        'catalogEdit' => [['WashService', 'edit', 'yes'], ['Work', 'edit', 'yes']],
        'stockView' => [['Supply', 'read', 'yes']],
        'stockEdit' => [['Supply', 'edit', 'yes'], ['SupplyReceipt', 'create', 'yes'], ['Stocktake', 'create', 'yes']],
        'mailings' => [['Mailing', 'create', 'yes'], ['Mailing', 'edit', 'yes']],
        'revenue' => 'revenue',
        'accounting' => 'accounting',
        'export' => 'export',
        'wages' => 'admin',
        'settings' => 'admin',
    ];

    public function __construct(private EntityManager $entityManager)
    {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $roles = $this->entityManager->getRDBRepository('Role')->find();
        $byKind = [];
        $known = [];

        foreach (array_keys(RoleSeeder::NAMES) as $kind) {
            $role = RoleSeeder::findRole($this->entityManager, $kind);

            if ($role) {
                $byKind[$kind] = $role;
                $known[$role->getId()] = true;
            }
        }

        $kioskRole = $this->entityManager->getRDBRepository('Role')->where(['name' => DeviceGuard::ROLE_NAME])->findOne();

        $users = $this->staffUsers();
        $levels = [];

        // Owner / administrator
        $admins = array_values(array_filter($users, fn ($u) => $u['type'] === 'admin'));
        $levels[] = [
            'kind' => 'owner',
            'roleId' => null,
            'roleName' => null,
            'capabilities' => $this->caps(null, true),
            'users' => $admins,
            'drift' => [],
        ];

        foreach (['manager', 'operator'] as $kind) {
            $role = $byKind[$kind] ?? null;

            $levels[] = [
                'kind' => $kind,
                'roleId' => $role?->getId(),
                'roleName' => $role?->get('name'),
                'capabilities' => $this->caps($role, false),
                'users' => $role ? $this->usersOf($role, $users) : [],
                'drift' => $role ? $this->drift($role, $kind) : [],
            ];
        }

        $other = [];

        foreach ($roles as $role) {
            if (isset($known[$role->getId()]) || ($kioskRole && $role->getId() === $kioskRole->getId())) {
                continue;
            }

            $other[] = [
                'kind' => 'other',
                'roleId' => $role->getId(),
                'roleName' => $role->get('name'),
                'capabilities' => $this->caps($role, false),
                'users' => $this->usersOf($role, $users),
                'drift' => [],
            ];
        }

        $noRole = array_values(array_filter($users, fn ($u) => $u['type'] !== 'admin' && $u['roles'] === []));

        return [
            'capabilityKeys' => array_keys(self::CAPABILITIES),
            'levels' => $levels,
            'otherRoles' => $other,
            'usersWithoutRole' => $noRole,
            'workers' => $this->workers($kioskRole),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function staffUsers(): array
    {
        $list = [];
        $users = $this->entityManager->getRDBRepository('User')
            ->where(['type' => ['admin', 'regular'], 'isActive' => true])
            ->order('userName')
            ->find();

        foreach ($users as $u) {
            $roles = [];

            foreach ($this->entityManager->getRDBRepository('User')->getRelation($u, 'roles')->find() as $r) {
                $roles[] = $r->getId();
            }

            $list[] = [
                'id' => $u->getId(),
                'name' => trim((string) $u->get('name')) !== '' ? $u->get('name') : $u->get('userName'),
                'userName' => $u->get('userName'),
                'type' => $u->get('type'),
                'roles' => $roles,
            ];
        }

        return $list;
    }

    /** @param list<array<string, mixed>> $users @return list<array<string, mixed>> */
    private function usersOf(Entity $role, array $users): array
    {
        $out = [];

        foreach ($users as $u) {
            if ($u['type'] !== 'admin' && in_array($role->getId(), $u['roles'], true)) {
                $out[] = ['id' => $u['id'], 'name' => $u['name'], 'userName' => $u['userName']];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function workers(?Entity $kioskRole): array
    {
        $em = $this->entityManager;
        $hired = $em->getRDBRepository('Employee')->where(['status' => 'Hired'])->count();
        $withPin = $em->getRDBRepository('Employee')->where(['status' => 'Hired', 'pinHash!=' => null])->count();
        $tablets = [];

        if ($kioskRole) {
            foreach ($em->getRDBRepository('Role')->getRelation($kioskRole, 'users')->find() as $u) {
                if ($u->get('isActive')) {
                    $tablets[] = ['id' => $u->getId(), 'name' => $u->get('name') ?: $u->get('userName')];
                }
            }
        }

        return ['hired' => $hired, 'withPin' => $withPin, 'tablets' => $tablets];
    }

    /** @return array<string, bool> */
    private function caps(?Entity $role, bool $admin): array
    {
        $out = [];

        foreach (self::CAPABILITIES as $key => $rule) {
            $out[$key] = $admin || $this->allowed($rule, $role);
        }

        return $out;
    }

    /** @param array<int, array<int, string>>|string $rule */
    private function allowed(array|string $rule, ?Entity $role): bool
    {
        if (!$role) {
            return false;
        }

        if ($rule === 'admin') {
            return false;
        }

        if ($rule === 'revenue') {
            return $this->level($role, 'WashOrder', 'read') === 3 && $this->switchOn($role, 'RevenueReports');
        }

        if ($rule === 'accounting') {
            return $this->allowed('revenue', $role);
        }

        if ($rule === 'export') {
            return $role->get('exportPermission') === 'yes';
        }

        foreach ($rule as [$scope, $action, $need]) {
            $lvl = $this->level($role, $scope, $action);

            if ($need === 'all' ? $lvl < 3 : $lvl < 1) {
                return false;
            }
        }

        return true;
    }

    private function rightOf(Entity $role, string $scope): mixed
    {
        $data = $role->get('data');
        $data = is_object($data) ? get_object_vars($data) : (is_array($data) ? $data : []);

        return $data[$scope] ?? false;
    }

    private function switchOn(Entity $role, string $scope): bool
    {
        $v = $this->rightOf($role, $scope);

        return $v === true || $v === 'yes' || $v === 'all';
    }

    /** no = 0, own = 1, team = 2, yes/all = 3 */
    private function level(Entity $role, string $scope, string $action): int
    {
        $v = $this->rightOf($role, $scope);

        if ($v === true) {
            return 3;
        }

        $v = is_object($v) ? get_object_vars($v) : (is_array($v) ? $v : null);

        if ($v === null) {
            return 0;
        }

        return match ($v[$action] ?? 'no') {
            'yes', 'all' => 3,
            'team' => 2,
            'own' => 1,
            default => 0,
        };
    }

    /**
     * Scopes where the stored role differs from the preset (RoleSeeder::rights) - the owner edited it by hand.
     *
     * @return list<string>
     */
    private function drift(Entity $role, string $kind): array
    {
        $preset = RoleSeeder::rights($kind);
        $scopes = array_unique(array_merge(array_keys($preset), $this->storedScopes($role)));
        $diff = [];

        foreach ($scopes as $scope) {
            if ($scope === 'RevenueReports') {
                if ($this->switchOn($role, $scope) !== (($preset[$scope] ?? false) === true)) {
                    $diff[] = $scope;
                }

                continue;
            }

            foreach (['create', 'read', 'edit', 'delete'] as $a) {
                $want = $preset[$scope][$a] ?? 'no';
                $have = $this->rawAction($role, $scope, $a);

                if ($want !== $have) {
                    $diff[] = $scope;

                    break;
                }
            }
        }

        sort($diff);

        return $diff;
    }

    /** @return list<string> */
    private function storedScopes(Entity $role): array
    {
        $data = $role->get('data');

        return is_object($data) ? array_keys(get_object_vars($data)) : [];
    }

    private function rawAction(Entity $role, string $scope, string $action): string
    {
        $v = $this->rightOf($role, $scope);

        if ($v === true) {
            return 'all';
        }

        $v = is_object($v) ? get_object_vars($v) : (is_array($v) ? $v : []);

        return (string) ($v[$action] ?? 'no');
    }
}
