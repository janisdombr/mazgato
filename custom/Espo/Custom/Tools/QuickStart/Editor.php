<?php

namespace Espo\Custom\Tools\QuickStart;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Custom\Tools\Validation\Numbers;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * The data behind the one-table editor of the wizard (`#Admin/quickStart`): the whole catalogue in one answer and one
 * transactional save. The editor replaces hand-made many-to-many links:
 *   service row  -> WashService (+ its ServiceWork lines)
 *   line         -> ServiceWork (work, optional role override) + the WorkRate (work x service, no employee) = the rate in EUR
 *   work         -> Work (+ baseRate = the fallback when a line has no own rate)
 *   role         -> WorkRole (+ defaultEmployee = who Quick Intake pre-selects)
 *   employee     -> Employee (+ workRole, salaryRate, status)
 *
 * Save payload = the same shape. Item with `id` is updated, without `id` created (its `key` "new:xyz" may be referred to by
 * other items in the same request instead of an id), `_delete: true` removes it. A service that carries `lines` has its lines
 * REPLACED by that list; without `lines` they are untouched. Everything is validated first, then written in one transaction -
 * a failure at any step leaves nothing behind. Saving the state it just read changes nothing (idempotent).
 */
class Editor
{
    public const MAX_ITEMS = 500;

    public function __construct(private EntityManager $entityManager)
    {}

    /** @return array<string,mixed> */
    public function state(): array
    {
        $em = $this->entityManager;
        $rows = fn (string $t, string $order) => $em->getRDBRepository($t)->order($order)->find();

        $roles = [];

        foreach ($rows('WorkRole', 'sortOrder') as $r) {
            $roles[] = ['id' => $r->getId(), 'name' => $r->get('name'), 'isActive' => (bool) $r->get('isActive'),
                'sortOrder' => (int) $r->get('sortOrder'), 'defaultEmployeeId' => $r->get('defaultEmployeeId')];
        }

        $employees = [];

        foreach ($rows('Employee', 'name') as $e) {
            $employees[] = ['id' => $e->getId(), 'name' => $e->get('name'), 'status' => $e->get('status') ?: 'Hired',
                'workRoleId' => $e->get('workRoleId'), 'salaryRate' => $e->get('salaryRate') !== null ? (float) $e->get('salaryRate') : null];
        }

        $works = [];
        $workName = [];

        foreach ($rows('Work', 'sortOrder') as $w) {
            $workName[$w->getId()] = $w->get('name');
            $works[] = ['id' => $w->getId(), 'name' => $w->get('name'), 'isActive' => (bool) $w->get('isActive'),
                'defaultRoleId' => $w->get('defaultRoleId'), 'sortOrder' => (int) $w->get('sortOrder'),
                'baseRate' => $w->get('baseRate') !== null ? (float) $w->get('baseRate') : null];
        }

        $rate = [];

        foreach ($rows('WorkRate', 'createdAt') as $r) {
            if ($r->get('serviceId') && !$r->get('employeeId')) {
                $rate[$r->get('serviceId') . '|' . $r->get('workId')] = (float) $r->get('rate');
            }
        }

        $lines = [];

        foreach ($em->getRDBRepository('ServiceWork')->order('sortOrder')->find() as $l) {
            $k = $l->get('serviceId') . '|' . $l->get('workId');
            $lines[$l->get('serviceId')][] = ['id' => $l->getId(), 'workId' => $l->get('workId'), 'workName' => $workName[$l->get('workId')] ?? '',
                'roleId' => $l->get('roleId'), 'rate' => $rate[$k] ?? null];
        }

        $services = [];

        foreach ($rows('WashService', 'sortOrder') as $s) {
            $services[] = ['id' => $s->getId(), 'name' => $s->get('name'), 'category' => $s->get('category') ?: 'Base',
                'isActive' => (bool) $s->get('isActive'), 'sortOrder' => (int) $s->get('sortOrder'),
                'priceRetail' => $s->get('priceRetail') !== null ? (float) $s->get('priceRetail') : null,
                'priceCorporate' => $s->get('priceCorporate') !== null ? (float) $s->get('priceCorporate') : null,
                'durationMinutes' => $s->get('durationMinutes') !== null ? (int) $s->get('durationMinutes') : null,
                'lines' => $lines[$s->getId()] ?? []];
        }

        return ['currency' => 'EUR', 'roles' => $roles, 'employees' => $employees, 'works' => $works, 'services' => $services];
    }

    // ---------------------------------------------------------------- validation

    /** @return list<array<string,mixed>> */
    private function list(stdClass $d, string $key): array
    {
        $v = $d->$key ?? [];

        if (!is_array($v) || count($v) > self::MAX_ITEMS) {
            throw new BadRequest("invalid_payload: '$key' must be a list of at most " . self::MAX_ITEMS . ' items.');
        }

        return array_map(fn ($x) => is_object($x) ? (array) $x : throw new BadRequest("invalid_payload: '$key' items must be objects."), $v);
    }

    private function name(array $it, string $what): string
    {
        $n = trim((string) ($it['name'] ?? ''));

        if ($n === '' || mb_strlen($n) > 150) {
            throw new BadRequest("invalid_name: the name of a $what is required (1-150 characters).");
        }

        return $n;
    }

    private function num(array $it, string $f, float $max, bool $int = false): ?float
    {
        $v = $it[$f] ?? null;

        if ($v === null || $v === '') {
            return null;
        }

        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }

        Numbers::assertNumber($f, $v, 0.0, $max);

        if ($int && (float) $v != floor((float) $v)) {
            throw new BadRequest("invalid_number: '$f' must be a whole number.");
        }

        return (float) $v;
    }

    /**
     * Validate everything that does not need the database; throws 400 naming the field.
     *
     * @param array<string,list<array<string,mixed>>> $p
     */
    private function validate(array $p): void
    {
        foreach ($p['roles'] as $it) {
            if (empty($it['_delete'])) {
                $this->name($it, 'role');
            }
        }

        foreach ($p['employees'] as $it) {
            if (empty($it['_delete'])) {
                $this->name($it, 'employee');
                $this->num($it, 'salaryRate', 1000000);

                if (isset($it['status']) && !in_array($it['status'], ['Hired', 'OnLeave', 'Dismissed'], true)) {
                    throw new BadRequest("invalid_value: 'status' must be Hired, OnLeave or Dismissed.");
                }
            }
        }

        foreach ($p['works'] as $it) {
            if (empty($it['_delete'])) {
                $this->name($it, 'work');
                $this->num($it, 'baseRate', 100000);
            }
        }

        foreach ($p['services'] as $it) {
            if (!empty($it['_delete'])) {
                continue;
            }

            $this->name($it, 'service');
            $this->num($it, 'priceRetail', 100000);
            $this->num($it, 'priceCorporate', 100000);
            $this->num($it, 'durationMinutes', 1440, true);

            if (isset($it['category']) && !in_array($it['category'], ['Base', 'Add-on'], true)) {
                throw new BadRequest("invalid_value: 'category' must be Base or Add-on.");
            }

            $seen = [];

            foreach ((array) ($it['lines'] ?? []) as $l) {
                $l = (array) $l;
                $wid = (string) ($l['workId'] ?? '');

                if ($wid === '') {
                    throw new BadRequest("invalid_payload: a line of '" . ($it['name'] ?? '') . "' has no work.");
                }

                if (isset($seen[$wid])) {
                    throw new BadRequest("duplicate_work: the same work is listed twice in '" . ($it['name'] ?? '') . "'.");
                }

                $seen[$wid] = true;
                $this->num($l, 'rate', 100000);
            }
        }
    }

    // ---------------------------------------------------------------- save

    /** @return array<string,mixed> state after the save + `changed` counters + `keys` (new:xyz -> id) */
    public function save(stdClass $data): array
    {
        $p = [];

        foreach (['roles', 'employees', 'works', 'services'] as $k) {
            $p[$k] = $this->list($data, $k);
        }

        $this->validate($p);

        $em = $this->entityManager;
        $keys = [];
        $changed = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        $ref = function (mixed $v) use (&$keys): ?string {
            if ($v === null || $v === '') {
                return null;
            }

            $v = (string) $v;

            if (str_starts_with($v, 'new:')) {
                return $keys[$v] ?? throw new BadRequest("invalid_payload: unknown reference '$v'.");
            }

            return $v;
        };
        $apply = function (string $type, array $it, array $values, string $what) use ($em, &$keys, &$changed): ?Entity {
            // "<field>Currency" belongs to an amount; an empty amount has no currency. Writing 'EUR' next to a NULL amount made
            // every read-and-save of the state "change" such a row (null -> EUR) and write it.
            foreach ($values as $k => $v) {
                if (str_ends_with($k, 'Currency') && array_key_exists(substr($k, 0, -8), $values) && $values[substr($k, 0, -8)] === null) {
                    unset($values[$k]);
                }
            }

            if (!empty($it['id'])) {
                $e = $em->getEntityById($type, (string) $it['id']) ?? throw new BadRequest("not_found: $what " . $it['id'] . ' does not exist.');

                // a partial payload must not reset what it does not mention
                foreach ($values as $k => $v) {
                    if (!array_key_exists($k, $it) && !str_ends_with($k, 'Currency')) {
                        unset($values[$k]);
                    }
                }

                $e->set($values);

                if ($this->dirty($e)) {
                    $em->saveEntity($e);
                    $changed['updated']++;
                }

                return $e;
            }

            $dup = $type === 'Employee' ? null : $em->getRDBRepository($type)->where(['name' => $values['name']])
                ->where($type === 'WashService' ? ['category' => $values['category'] ?? 'Base'] : [])->findOne();

            if ($dup) {
                throw new Conflict("duplicate_name: a $what named '" . $values['name'] . "' already exists.");
            }

            $e = $em->getNewEntity($type);
            $e->set($values);
            $em->saveEntity($e);
            $changed['created']++;

            if (!empty($it['key'])) {
                $keys[(string) $it['key']] = $e->getId();
            }

            return $e;
        };

        $tx = $em->getTransactionManager();
        $tx->start();

        try {
            // deletes first: they free names and run the in-use guards before anything else changes
            foreach (['services' => 'WashService', 'works' => 'Work', 'employees' => 'Employee', 'roles' => 'WorkRole'] as $k => $type) {
                foreach ($p[$k] as $it) {
                    if (!empty($it['_delete']) && !empty($it['id'])) {
                        $this->remove($type, (string) $it['id']);
                        $changed['deleted']++;
                    }
                }
            }

            foreach ($p['roles'] as $it) {
                if (!empty($it['_delete'])) {
                    continue;
                }

                $apply('WorkRole', $it, [
                    'name' => $this->name($it, 'role'), 'isActive' => $it['isActive'] ?? true,
                ] + (isset($it['sortOrder']) ? ['sortOrder' => (int) $it['sortOrder']] : (empty($it['id']) ? ['sortOrder' => $this->nextSort('WorkRole', 10)] : [])), 'role');
            }

            foreach ($p['employees'] as $it) {
                if (!empty($it['_delete'])) {
                    continue;
                }

                $apply('Employee', $it, [
                    'name' => $this->name($it, 'employee'), 'status' => $it['status'] ?? 'Hired',
                    'workRoleId' => $ref($it['workRoleId'] ?? null), 'salaryRate' => $this->num($it, 'salaryRate', 1000000),
                ], 'employee');
            }

            // role -> default employee (the employee may have been created just above)
            foreach ($p['roles'] as $it) {
                if (!empty($it['_delete']) || !array_key_exists('defaultEmployeeId', $it)) {
                    continue;
                }

                $rid = !empty($it['id']) ? (string) $it['id'] : ($keys[(string) ($it['key'] ?? '')] ?? null);
                $role = $rid ? $em->getEntityById('WorkRole', $rid) : null;

                if ($role) {
                    $eid = $ref($it['defaultEmployeeId']);

                    if ($eid && !$em->getEntityById('Employee', $eid)) {
                        throw new BadRequest('not_found: the default employee does not exist.');
                    }

                    $role->set('defaultEmployeeId', $eid);

                    if ($this->dirty($role)) {
                        $em->saveEntity($role);
                        $changed['updated']++;
                    }
                }
            }

            foreach ($p['works'] as $it) {
                if (!empty($it['_delete'])) {
                    continue;
                }

                $rate = $this->num($it, 'baseRate', 100000);
                $apply('Work', $it, [
                    'name' => $this->name($it, 'work'), 'isActive' => $it['isActive'] ?? true,
                    'defaultRoleId' => $ref($it['defaultRoleId'] ?? null), 'baseRate' => $rate, 'baseRateCurrency' => 'EUR',
                ] + (empty($it['id']) ? ['sortOrder' => $this->nextSort('Work', 10)] : []), 'work');
            }

            foreach ($p['services'] as $it) {
                if (!empty($it['_delete'])) {
                    continue;
                }

                $cat = $it['category'] ?? 'Base';
                $dur = $this->num($it, 'durationMinutes', 1440, true);
                $s = $apply('WashService', $it, [
                    'name' => $this->name($it, 'service'), 'category' => $cat, 'isActive' => $it['isActive'] ?? true,
                    'priceRetail' => $this->num($it, 'priceRetail', 100000), 'priceRetailCurrency' => 'EUR',
                    'priceCorporate' => $this->num($it, 'priceCorporate', 100000), 'priceCorporateCurrency' => 'EUR',
                    'durationMinutes' => $dur === null ? null : (int) $dur,
                ] + (empty($it['id']) ? ['sortOrder' => $this->nextSort('WashService', 10)] : []), 'service');

                if ($s && array_key_exists('lines', $it)) {
                    $this->syncLines($s->getId(), (array) $it['lines'], $ref, $changed);
                }
            }

            $tx->commit();
        } catch (\Throwable $ex) {
            $tx->rollback();

            throw $ex;
        }

        return ['ok' => true, 'changed' => $changed, 'keys' => (object) $keys, 'state' => $this->state()];
    }

    private function dirty(Entity $e): bool
    {
        if ($e->isNew()) {
            return true;
        }

        foreach ($e->getAttributeList() as $a) {
            if ($e->isAttributeChanged($a)) {
                return true;
            }
        }

        return false;
    }

    private function nextSort(string $type, int $step): int
    {
        $max = 0;

        foreach ($this->entityManager->getRDBRepository($type)->select(['id', 'sortOrder'])->find() as $e) {
            $max = max($max, (int) $e->get('sortOrder'));
        }

        return $max + $step;
    }

    /** @param callable $ref @param array<string,int> $changed */
    private function syncLines(string $serviceId, array $lines, callable $ref, array &$changed): void
    {
        $em = $this->entityManager;
        $existing = [];

        foreach ($em->getRDBRepository('ServiceWork')->where(['serviceId' => $serviceId])->find() as $l) {
            $existing[$l->get('workId')] = $l;
        }

        $rates = [];

        foreach ($em->getRDBRepository('WorkRate')->where(['serviceId' => $serviceId, 'employeeId' => null])->find() as $r) {
            $rates[$r->get('workId')][] = $r;
        }

        // The list order is the line order. Renumber (0, 1, 2 ...) only when the stored sortOrders do not already say the same
        // thing - a new line, or lines out of order (equal values are fine: the read order is kept). Rewriting 1,2,3 as 0,1,2 would "change" an untouched service on every save.
        $renumber = false;
        $prev = null;

        foreach ($lines as $l) {
            $ex = $existing[$ref(((array) $l)['workId'] ?? null) ?? ''] ?? null;

            if (!$ex || ($prev !== null && (int) $ex->get('sortOrder') < $prev)) {
                $renumber = true;
                break;
            }

            $prev = (int) $ex->get('sortOrder');
        }

        $keep = [];

        foreach ($lines as $n => $l) {
            $l = (array) $l;
            $wid = $ref($l['workId']);

            if (!$wid || !$em->getEntityById('Work', $wid)) {
                throw new BadRequest('not_found: a line points at a work that does not exist.');
            }

            $keep[$wid] = true;
            $role = $ref($l['roleId'] ?? null);
            $line = $existing[$wid] ?? null;

            if (!$line) {
                $line = $em->getNewEntity('ServiceWork');
                $line->set(['serviceId' => $serviceId, 'workId' => $wid]);
                $changed['created']++;
            }

            $line->set($renumber ? ['roleId' => $role, 'sortOrder' => $n] : ['roleId' => $role]);

            if ($this->dirty($line)) {
                $em->saveEntity($line);

                if (!isset($existing[$wid])) {
                    // counted as created above
                } else {
                    $changed['updated']++;
                }
            }

            $rate = $this->num($l, 'rate', 100000);
            $rows = $rates[$wid] ?? [];

            if ($rate === null) {
                foreach ($rows as $r) {
                    $em->removeEntity($r);
                    $changed['deleted']++;
                }
            } else {
                $r = $rows[0] ?? null;

                if (!$r) {
                    $r = $em->getNewEntity('WorkRate');
                    $r->set(['workId' => $wid, 'serviceId' => $serviceId]);
                    $changed['created']++;
                }

                $r->set(['rate' => $rate, 'rateCurrency' => 'EUR']);

                if ($this->dirty($r)) {
                    $em->saveEntity($r);

                    if (!$r->isNew() && $rows) {
                        $changed['updated']++;
                    }
                }

                foreach (array_slice($rows, 1) as $extra) { // a stray duplicate would make the first-hit rule ambiguous
                    $em->removeEntity($extra);
                    $changed['deleted']++;
                }
            }
        }

        foreach ($existing as $wid => $line) {
            if (!isset($keep[$wid])) {
                $em->removeEntity($line);
                $changed['deleted']++;

                foreach ($rates[$wid] ?? [] as $r) {
                    $em->removeEntity($r);
                }
            }
        }
    }

    /** Deleting is refused (409 in_use) for anything an order points at: deactivate it instead, history stays readable. */
    private function remove(string $type, string $id): void
    {
        $em = $this->entityManager;
        $e = $em->getEntityById($type, $id);

        if (!$e) {
            return;
        }

        $used = match ($type) {
            'WashService' => $this->count('WashOrder', ['serviceId' => $id]) + $this->liveLines('OrderAddon', ['serviceId' => $id]) + $this->liveLines('OrderWork', ['serviceId' => $id]),
            'Work' => $this->liveLines('OrderWork', ['workId' => $id]),
            'Employee' => $this->liveLines('OrderWork', ['employeeId' => $id]) + $this->count('Shift', ['employeeId' => $id]),
            default => 0,
        };

        if ($used > 0) {
            throw new Conflict("in_use: '" . $e->get('name') . "' is used by $used record(s) - deactivate it instead of deleting.");
        }

        switch ($type) {
            case 'WashService':
                $this->removeWhere('ServiceWork', ['serviceId' => $id]);
                $this->removeWhere('WorkRate', ['serviceId' => $id]);
                break;
            case 'Work':
                $this->removeWhere('ServiceWork', ['workId' => $id]);
                $this->removeWhere('WorkRate', ['workId' => $id]);
                break;
            case 'Employee':
                $this->removeWhere('WorkRate', ['employeeId' => $id]);
                $this->nullOut('WorkRole', 'defaultEmployeeId', $id);
                break;
            case 'WorkRole':
                $this->nullOut('Work', 'defaultRoleId', $id);
                $this->nullOut('Employee', 'workRoleId', $id);
                $this->nullOut('ServiceWork', 'roleId', $id);
                break;
        }

        $em->removeEntity($e);
    }

    /** Order lines that still belong to a LIVE order (lines left behind by a deleted order do not block a delete). */
    private function liveLines(string $type, array $where): int
    {
        $n = 0;

        foreach ($this->entityManager->getRDBRepository($type)->select(['id', 'washOrderId'])->where($where)->find() as $l) {
            if ($l->get('washOrderId') && $this->entityManager->getEntityById('WashOrder', $l->get('washOrderId'))) {
                $n++;
            }
        }

        return $n;
    }

    /** @param array<string,mixed> $where */
    private function count(string $type, array $where): int
    {
        return $this->entityManager->getRDBRepository($type)->where($where)->count();
    }

    /** @param array<string,mixed> $where */
    private function removeWhere(string $type, array $where): void
    {
        foreach ($this->entityManager->getRDBRepository($type)->where($where)->find() as $e) {
            $this->entityManager->removeEntity($e);
        }
    }

    private function nullOut(string $type, string $field, string $id): void
    {
        foreach ($this->entityManager->getRDBRepository($type)->where([$field => $id])->find() as $e) {
            $e->set($field, null);
            $this->entityManager->saveEntity($e);
        }
    }
}
