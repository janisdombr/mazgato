<?php

namespace Espo\Custom\Tools\QuickStart;

use Espo\ORM\EntityManager;

/**
 * Completeness check of the catalogue: is there anything that would silently produce a 0 EUR wage line, a service that
 * brings no work, a role with nobody to hand the work to. Pure reads; the wizard page and the dashlet badge show the result.
 *
 * Every issue: {code, severity error|warning, entityType, id, name, focus ("kind:id" for the wizard), url (record), params}.
 * Texts are NOT here - the client translates by `code` (QuickStart.json), so the answer stays language-free.
 */
class Checker
{
    public function __construct(private EntityManager $entityManager)
    {}

    /** @return array{issues: list<array<string,mixed>>, errors: int, warnings: int} */
    public function run(): array
    {
        $em = $this->entityManager;
        $issues = [];
        $add = function (string $code, string $sev, string $type, ?string $id, ?string $name, string $kind, array $params = []) use (&$issues) {
            $issues[] = [
                'code' => $code, 'severity' => $sev, 'entityType' => $type, 'id' => $id, 'name' => $name,
                'focus' => $kind . ($id ? ':' . $id : ''), 'url' => $id ? "#$type/view/$id" : null, 'params' => (object) $params,
            ];
        };

        $roles = iterator_to_array($em->getRDBRepository('WorkRole')->order('sortOrder')->find(), false);
        $works = iterator_to_array($em->getRDBRepository('Work')->order('sortOrder')->find(), false);
        $services = iterator_to_array($em->getRDBRepository('WashService')->order('sortOrder')->find(), false);
        $employees = iterator_to_array($em->getRDBRepository('Employee')->find(), false);
        $lines = iterator_to_array($em->getRDBRepository('ServiceWork')->find(), false);
        $rates = iterator_to_array($em->getRDBRepository('WorkRate')->find(), false);

        $byId = fn (array $list): array => array_combine(array_map(fn ($e) => $e->getId(), $list), $list) ?: [];
        $roleById = $byId($roles);
        $workById = $byId($works);
        $empById = $byId($employees);

        $hired = array_filter($employees, fn ($e) => ($e->get('status') ?: 'Hired') === 'Hired');

        if (!array_filter($services, fn ($s) => $s->get('isActive') && $s->get('category') === 'Base')) {
            $add('no_base_service', 'error', 'WashService', null, null, 'services');
        }

        if (!$hired) {
            $add('no_employees', 'error', 'Employee', null, null, 'employees');
        }

        if (!array_filter($roles, fn ($r) => $r->get('isActive'))) {
            $add('no_roles', 'error', 'WorkRole', null, null, 'roles');
        }

        // rates indexed by work
        $rateByWork = [];

        foreach ($rates as $r) {
            if ((float) $r->get('rate') > 0) {
                $rateByWork[$r->get('workId')] = true;
            }
        }

        $linesByService = [];
        $workUsed = [];

        foreach ($lines as $l) {
            $linesByService[$l->get('serviceId')][] = $l;
            $workUsed[$l->get('workId')] = true;
        }

        foreach ($services as $s) {
            if (!$s->get('isActive')) {
                continue;
            }

            $id = $s->getId();
            $nm = $s->get('name');

            if (empty($linesByService[$id])) {
                $add('service_no_works', 'error', 'WashService', $id, $nm, 'service');
            } else {
                foreach ($linesByService[$id] as $l) {
                    $w = $workById[$l->get('workId')] ?? null;

                    if ($w && !$w->get('isActive')) {
                        $add('service_work_inactive', 'warning', 'WashService', $id, $nm, 'service', ['work' => $w->get('name')]);
                    }
                }
            }

            if (!((float) $s->get('priceRetail') > 0)) {
                $add('service_no_price', 'error', 'WashService', $id, $nm, 'service');
            }

            if (!((int) $s->get('durationMinutes') > 0)) {
                $add('service_no_duration', 'warning', 'WashService', $id, $nm, 'service');
            }

            if ($s->get('priceCorporate') === null || $s->get('priceCorporate') === '') {
                $add('service_no_corporate_price', 'warning', 'WashService', $id, $nm, 'service');
            }
        }

        foreach ($works as $w) {
            if (!$w->get('isActive')) {
                continue;
            }

            $id = $w->getId();
            $nm = $w->get('name');

            if (!((float) $w->get('baseRate') > 0) && empty($rateByWork[$id])) {
                $add('work_no_rate', 'error', 'Work', $id, $nm, 'work');
            }

            if (empty($workUsed[$id])) {
                $add('work_no_service', 'warning', 'Work', $id, $nm, 'work');
            }

            $rid = $w->get('defaultRoleId');

            if (!$rid || !isset($roleById[$rid])) {
                $add('work_no_role', 'warning', 'Work', $id, $nm, 'work');
            } elseif (!$roleById[$rid]->get('isActive')) {
                $add('work_role_inactive', 'warning', 'Work', $id, $nm, 'work', ['role' => $roleById[$rid]->get('name')]);
            }
        }

        foreach ($roles as $r) {
            if (!$r->get('isActive')) {
                continue;
            }

            $id = $r->getId();
            $nm = $r->get('name');
            $did = $r->get('defaultEmployeeId');
            $members = array_filter($hired, fn ($e) => $e->get('workRoleId') === $id);

            if (!$did || !isset($empById[$did])) {
                $add('role_no_default_employee', 'error', 'WorkRole', $id, $nm, 'role');
            } elseif (($empById[$did]->get('status') ?: 'Hired') !== 'Hired') {
                $add('role_default_employee_inactive', 'error', 'WorkRole', $id, $nm, 'role', ['employee' => $empById[$did]->get('name')]);
            }

            if (!$members) {
                $add('role_no_employees', 'warning', 'WorkRole', $id, $nm, 'role');
            }
        }

        foreach ($hired as $e) {
            $rid = $e->get('workRoleId');

            if (!$rid || !isset($roleById[$rid])) {
                $add('employee_no_role', 'warning', 'Employee', $e->getId(), $e->get('name'), 'employee');
            } elseif (!$roleById[$rid]->get('isActive')) {
                $add('employee_role_inactive', 'warning', 'Employee', $e->getId(), $e->get('name'), 'employee', ['role' => $roleById[$rid]->get('name')]);
            }
        }

        return [
            'issues' => $issues,
            'errors' => count(array_filter($issues, fn ($i) => $i['severity'] === 'error')),
            'warnings' => count(array_filter($issues, fn ($i) => $i['severity'] === 'warning')),
        ];
    }
}
