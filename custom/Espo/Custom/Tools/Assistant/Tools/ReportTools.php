<?php

namespace Espo\Custom\Tools\Assistant\Tools;

use Espo\Custom\Controllers\CarWash;
use Espo\Custom\Tools\Assistant\InternalRequest;
use Espo\Custom\Tools\Assistant\Toolset;
use Espo\Custom\Tools\Assistant\Untrusted;

/**
 * Figures. Every number comes from the same CarWash actions the dashlets call (statistics,
 * payroll, supplyUsage) — these tools only trim, mask and label what those return.
 * Aggregates cannot be filtered row by row, so the role must read the WHOLE scope.
 */
class ReportTools extends Toolset
{
    private ?CarWash $cw = null;

    private function cw(): CarWash
    {
        return $this->cw ??= $this->ctx->factory->create(CarWash::class);
    }

    public function specs(): array
    {
        return [
            [
                'name' => 'statistics',
                'description' => 'Orders, revenue (completed orders only), average check, unpaid amount, payment-method split, top services, top customers, labour cost and margin for a period, with the same figures for the previous period of equal length and the change. Same numbers as the Statistics dashboard.',
                'input_schema' => ['type' => 'object', 'properties' => $this->periodSchema(), 'additionalProperties' => false],
            ],
            [
                'name' => 'load',
                'description' => 'How busy the wash is: visits by hour of the day and by weekday for a period, and the peak hour and day. Use for "when are we busiest / when is it quiet".',
                'input_schema' => ['type' => 'object', 'properties' => $this->periodSchema(), 'additionalProperties' => false],
            ],
            [
                'name' => 'payroll',
                'description' => 'Wages earned per employee and per kind of work over completed orders in a period (works done, amount), plus hours clocked.',
                'input_schema' => ['type' => 'object', 'properties' => $this->periodSchema(), 'additionalProperties' => false],
            ],
            [
                'name' => 'supply_stock',
                'description' => 'Supplies (chemicals, consumables): stock, reorder level, what is below it and by how much, and the value of the stock. Use for "what should we order".',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'onlyReorder' => ['type' => 'boolean', 'description' => 'true (default): only supplies at or below the reorder level.'],
                ], 'additionalProperties' => false],
            ],
            [
                'name' => 'supply_usage',
                'description' => 'How much of each supply the completed orders of a period consumed and what it cost, per supply and per service.',
                'input_schema' => ['type' => 'object', 'properties' => $this->periodSchema(), 'additionalProperties' => false],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        $p = $this->period($args);

        if (in_array($name, ['statistics', 'load', 'payroll', 'supply_usage'], true) && !$p) {
            return $this->fail(400, 'dateFrom/dateTo must be YYYY-MM-DD, dateTo not before dateFrom, at most 400 days.');
        }

        return match ($name) {
            'statistics' => $this->statistics($p),
            'load' => $this->load($p),
            'payroll' => $this->payroll($p),
            'supply_stock' => $this->stock($args),
            'supply_usage' => $this->usage($p),
            default => $this->fail(404, 'unknown tool'),
        };
    }

    /** @param array{0: string, 1: string} $p */
    private function body(array $p): InternalRequest
    {
        return new InternalRequest(['dateFrom' => $p[0] . ' 00:00:00', 'dateTo' => $p[1] . ' 23:59:59']);
    }

    /** @return array<string, mixed> */
    private function toArray(object $o): array
    {
        return json_decode((string) json_encode($o), true) ?: [];
    }

    private function pct(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    /** @param array{0: string, 1: string} $p */
    private function statistics(array $p): array
    {
        if (!$this->ctx->canAll('WashOrder') || !$this->ctx->canRevenue()) {
            return $this->denied('all orders and the revenue reports right (statistics)');
        }

        $s = $this->toArray($this->cw()->postActionStatistics($this->body($p)));
        $prev = (array) ($s['previous'] ?? []);
        $o = (array) ($s['orders'] ?? []);

        $out = [
            'source' => 'CarWash statistics (Statistics dashboard)',
            'period' => ['from' => $p[0], 'to' => $p[1]],
            'orders' => $o,
            'previousPeriod' => [
                'from' => substr((string) ($prev['dateFrom'] ?? ''), 0, 10), 'to' => substr((string) ($prev['dateTo'] ?? ''), 0, 10),
                'orders' => $prev['orders'] ?? null, 'completed' => $prev['completed'] ?? null,
                'revenue' => $prev['revenue'] ?? null, 'averageCheck' => $prev['averageCheck'] ?? null,
                'unpaid' => $prev['unpaid'] ?? null, 'labourCost' => $prev['labourCost'] ?? null, 'margin' => $prev['margin'] ?? null,
            ],
            'changePercent' => [
                'revenue' => $this->pct((float) ($o['revenue'] ?? 0), (float) ($prev['revenue'] ?? 0)),
                'completed' => $this->pct((float) ($o['completed'] ?? 0), (float) ($prev['completed'] ?? 0)),
                'averageCheck' => $this->pct((float) ($o['averageCheck'] ?? 0), (float) ($prev['averageCheck'] ?? 0)),
            ],
            'currency' => 'EUR',
            'topServices' => array_map(fn($r) => [
                'service' => Untrusted::clean((string) ($r['name'] ?? ''), 60), 'count' => $r['count'] ?? 0, 'sum' => $r['sum'] ?? 0,
            ], (array) ($s['services'] ?? [])),
            'newCustomers' => $s['customers']['new'] ?? 0,
            'topCustomers' => $this->topCustomers((array) ($s['customers']['top'] ?? [])),
        ];

        if ($this->ctx->user->isAdmin()) {
            $out['labour'] = $s['labour'] ?? null;
        }

        $days = (int) (new \DateTimeImmutable($p[0]))->diff(new \DateTimeImmutable($p[1]))->days + 1;

        if ($days <= 45) {
            $out['daily'] = array_values(array_map(fn($d) => [$d['date'], $d['orders'], $d['revenue']], array_filter((array) ($s['daily'] ?? []), fn($d) => $d['orders'] > 0)));
            $out['dailyColumns'] = ['date', 'orders', 'revenue'];
            $out['dailyNote'] = 'Only days with orders are listed.';
        }

        return $out;
    }

    /**
     * The dashboard's top-customer rows carry a name only. Companies keep theirs; private persons become K-n.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function topCustomers(array $rows): array
    {
        $out = [];

        foreach ($rows as $r) {
            $name = (string) ($r['name'] ?? '');
            $c = $name === '' ? null : $this->ctx->em->getRDBRepository('Customer')->where(['name' => $name, 'deleted' => false])->findOne();

            if ($c && !$this->ctx->canSee($c)) {
                continue;
            }

            $ref = $c ? $this->ctx->customerRef($c) : ['customer' => 'unknown'];
            $out[] = $ref + ['visits' => $r['count'] ?? 0, 'sum' => $r['sum'] ?? 0];
        }

        return $out;
    }

    /** @param array{0: string, 1: string} $p */
    private function load(array $p): array
    {
        if (!$this->ctx->canAll('WashOrder') || !$this->ctx->canRevenue()) {
            return $this->denied('all orders and the revenue reports right (load)');
        }

        $s = $this->toArray($this->cw()->postActionStatistics($this->body($p)));
        $byHour = array_values((array) ($s['byHour'] ?? []));
        $peak = $byHour ? array_keys($byHour, max($byHour))[0] : null;
        $names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $wd = array_fill_keys($names, ['days' => 0, 'orders' => 0]);

        foreach ((array) ($s['daily'] ?? []) as $d) {
            $n = $names[(int) (new \DateTimeImmutable($d['date']))->format('N') - 1];
            $wd[$n]['days']++;
            $wd[$n]['orders'] += (int) $d['orders'];
        }

        $byWeekday = [];

        foreach ($wd as $n => $v) {
            $byWeekday[$n] = ['orders' => $v['orders'], 'perDay' => $v['days'] ? round($v['orders'] / $v['days'], 1) : 0];
        }

        $best = null;

        foreach ($byWeekday as $n => $v) {
            if ($v['orders'] > 0 && ($best === null || $v['perDay'] > $byWeekday[$best]['perDay'])) {
                $best = $n;
            }
        }

        return [
            'source' => 'CarWash statistics (load by hour)',
            'period' => ['from' => $p[0], 'to' => $p[1]],
            'visitsByHour' => (object) array_filter(array_combine(array_map(fn($h) => sprintf('%02d', $h), array_keys($byHour)), $byHour), fn($v) => $v > 0),
            'peakHour' => $peak === null || max($byHour) === 0 ? null : sprintf('%02d:00', $peak),
            'byWeekday' => $byWeekday,
            'busiestWeekday' => $best,
            'note' => 'Hours are local time; a visit is counted at the hour work started.',
        ];
    }

    /** @param array{0: string, 1: string} $p */
    private function payroll(array $p): array
    {
        // Wages are the administrator's alone (docs/ROLES.md): no role, however wide, opens them - same rule as CarWash/payroll.
        if (!$this->ctx->user->isAdmin()) {
            return $this->denied('payroll (administrator only)');
        }

        $r = $this->toArray($this->cw()->postActionPayroll($this->body($p)));
        $s = $this->toArray($this->cw()->postActionStatistics($this->body($p)));
        $hours = [];

        foreach ((array) ($s['employees'] ?? []) as $e) {
            $hours[(string) $e['name']] = $e['hours'] ?? 0;
        }

        $employees = array_map(fn($row) => [
            'employee' => Untrusted::clean((string) $row['employeeName'], 60),
            'works' => $row['works'], 'earned' => $row['earned'], 'hours' => $hours[(string) $row['employeeName']] ?? 0,
        ], (array) ($r['rows'] ?? []));

        $works = [];
        $lines = $this->ctx->em->getRDBRepository('OrderWork')
            ->join('washOrder')
            ->where([
                'washOrder.status' => 'Completed',
                'washOrder.orderDateTime>=' => $this->ctx->utc($p[0] . ' 00:00:00'),
                'washOrder.orderDateTime<=' => $this->ctx->utc($p[1] . ' 23:59:59'),
                'deleted' => false,
            ])->find();

        foreach ($lines as $l) {
            $k = (string) ($l->get('workName') ?: '—');
            $works[$k] ??= ['work' => Untrusted::clean($k, 60), 'count' => 0, 'amount' => 0.0];
            $works[$k]['count']++;
            $works[$k]['amount'] += (float) $l->get('amount');
        }

        usort($works, fn($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'source' => 'CarWash payroll (Payroll dashlet)',
            'period' => ['from' => $p[0], 'to' => $p[1]],
            'currency' => 'EUR',
            'employees' => $employees,
            'byWork' => array_map(fn($w) => ['work' => $w['work'], 'count' => $w['count'], 'amount' => round($w['amount'], 2)], array_slice($works, 0, 30)),
            'totalWorks' => $r['totalWorks'] ?? 0,
            'totalEarned' => $r['totalEarned'] ?? 0,
        ];
    }

    private function stock(array $args): array
    {
        if (!$this->ctx->canRead('Supply')) {
            return $this->denied('supplies');
        }

        $only = !array_key_exists('onlyReorder', $args) || $args['onlyReorder'];
        $rows = [];
        $value = 0.0;
        $count = 0;

        foreach ($this->ctx->em->getRDBRepository('Supply')->where(['deleted' => false])->order('name')->find() as $s) {
            if (!$this->ctx->canSee($s)) {
                continue;
            }

            $count++;
            $stock = (float) $s->get('stockQuantity');
            $level = (float) $s->get('reorderLevel');
            $value += $stock * (float) $s->get('unitCost');
            $low = $stock <= $level && $level > 0 || (bool) $s->get('needsReorder');

            if ($only && !$low) {
                continue;
            }

            $rows[] = [
                'supply' => Untrusted::clean((string) $s->get('name'), 60), 'unit' => (string) $s->get('unit'),
                'stock' => round($stock, 2), 'reorderLevel' => round($level, 2),
                'belowBy' => round(max(0, $level - $stock), 2), 'needsReorder' => $low,
                'unitCost' => round((float) $s->get('unitCost'), 2), 'supplier' => Untrusted::clean((string) $s->get('supplier'), 60),
            ];
        }

        return [
            'source' => 'Supply list (needsReorder filter)',
            'asOf' => $this->ctx->today(),
            'currency' => 'EUR',
            'suppliesTotal' => $count,
            'listed' => count($rows),
            'stockValue' => round($value, 2),
            'supplies' => array_slice($rows, 0, 100),
        ];
    }

    /** @param array{0: string, 1: string} $p */
    private function usage(array $p): array
    {
        if (!$this->ctx->canAll('WashOrder') || !$this->ctx->canRevenue() || !$this->ctx->canRead('Supply')) {
            return $this->denied('supply usage');
        }

        $r = $this->toArray($this->cw()->postActionSupplyUsage($this->body($p)));

        return [
            'source' => 'CarWash supplyUsage (Supply Usage dashlet)',
            'period' => ['from' => $p[0], 'to' => $p[1]],
            'currency' => 'EUR',
            'ordersCount' => $r['ordersCount'] ?? 0, 'worksCount' => $r['worksCount'] ?? 0, 'totalCost' => $r['totalCost'] ?? 0,
            'perService' => array_map(fn($x) => [
                'service' => Untrusted::clean((string) ($x['serviceName'] ?? ''), 60), 'times' => $x['count'] ?? 0,
                'cost' => $x['cost'] ?? 0, 'costPerRun' => $x['costPerUnit'] ?? 0,
            ], array_slice((array) ($r['services'] ?? []), 0, 30)),
            'perSupply' => array_map(fn($x) => [
                'supply' => Untrusted::clean((string) ($x['supplyName'] ?? ''), 60), 'unit' => $x['unit'] ?? '',
                'quantity' => $x['quantity'] ?? 0, 'cost' => $x['cost'] ?? 0,
            ], array_slice((array) ($r['rows'] ?? []), 0, 40)),
        ];
    }
}
