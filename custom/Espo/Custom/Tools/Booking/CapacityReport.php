<?php

namespace Espo\Custom\Tools\Booking;

use Espo\ORM\EntityManager;

/**
 * What the Planner knows, laid out for the screens: the capacity calculator on the booking settings page (`preview`) and the order
 * calendar (`range`). It owns NO formula: every number comes from Planner (lines, washers, crew, hours, weights, peaks), so the
 * calculator, the calendar, the slot list and the reservation guard cannot disagree.
 */
class CapacityReport
{
    /** Statuses that use a day's capacity: everything but Cancelled. */
    private const COUNTED = ['Reserved', 'New', 'In Progress', 'Completed'];
    /** More days than this and the answer carries day totals only (the month view): no hours, no orders. */
    public const DETAIL_DAYS = 8;
    public const MAX_DAYS = 62;

    public function __construct(private EntityManager $em, private Planner $planner)
    {}

    // ------------------------------------------------------------------ calculator

    /**
     * Per service x weekday: lines, cars per hour / per line / per day, the online share, and warnings.
     * @param array<string, mixed> $values unsaved settings of the form (the stored ones for any key left out)
     */
    public function preview(array $values): array
    {
        $p = $this->planner->withOverrides($values);
        $tz = $p->tz();
        $today = $p->now()->setTimezone($tz)->setTime(0, 0);
        $buffer = $p->buffer();
        $cd = $p->crewDefault();
        $share = $p->onlineSharePercent();
        $maxDay = $p->maxPerDay();
        $maxHour = $p->maxPerHour();
        $services = [];
        $global = [];

        // one reference date per weekday: the next such day that is not closed by a date (a closed WEEKDAY stays closed)
        $ref = [];

        foreach ($p::DAYS as $name) {
            $first = null;

            for ($i = 0; $i < 60; $i++) {
                $d = $today->modify('+' . $i . ' days');

                if ($p::DAYS[(int) $d->format('w')] !== $name) {
                    continue;
                }

                $first ??= $d;

                if ($p->hoursFor($d)) {
                    $ref[$name] = $d;
                    break;
                }
            }

            $ref[$name] ??= $first;
        }

        $order = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        foreach ($order as $name) {
            $d = $ref[$name];

            if ($p->washersFor($d) < $p->posts() && $p->hoursFor($d)) {
                $global['washers_lt_posts'] = true;
            }
        }

        if ($share === 0) {
            $global['online_share_zero'] = true;
        }

        $q = $this->em->getRDBRepository('WashService')->where(['deleted' => false, 'isActive' => true, 'category' => 'Base'])->order('sortOrder')->find();

        foreach ($q as $s) {
            $minutes = (int) $s->get('durationMinutes');
            $run = $minutes > 0 ? $minutes : 30;
            $crew = $p->crewFor($s->getId());
            $row = ['id' => $s->getId(), 'name' => (string) $s->get('name'), 'minutes' => $minutes, 'crew' => $crew, 'days' => []];
            $svcWarn = [];

            if ($minutes <= 0) {
                $svcWarn['no_duration'] = true;
            }

            if ($buffer > $run) {
                $svcWarn['buffer_gt_duration'] = true;
            }

            foreach ($order as $name) {
                $d = $ref[$name];
                $h = $p->hoursFor($d);
                $washers = $p->washersFor($d);
                $cell = ['closed' => !$h, 'washers' => $washers, 'warnings' => []];

                if (!$h) {
                    $cell += ['open' => 0, 'lines' => 0, 'perHour' => 0.0, 'perLine' => 0, 'perDay' => 0, 'online' => 0];
                    $row['days'][$name] = $cell;
                    continue;
                }

                $open = $h[1] - $h[0];
                $lines = $p->linesFor($d, $crew);
                $cycle = $run + $buffer;
                $perLine = intdiv($open, $cycle);
                $byGrid = $lines * $perLine;
                $capMin = $p->dayCapacityMinutes($d);
                $w = $p->weight($run, $crew);
                $byMinutes = $w > 0 ? (int) floor($capMin / $w + 1e-9) : 0;
                $perDay = min($byGrid, $byMinutes);
                $online = min($perDay, $w > 0 ? (int) floor($capMin * $share / 100 / $w + 1e-9) : 0);
                $perHour = round($lines * 60 / $cycle, 1);

                if ($maxHour > 0 && $perHour > $maxHour) {
                    $perHour = (float) $maxHour;
                    $cell['warnings'][] = 'hour_cap_binds';
                }

                if ($maxDay > 0 && $perDay > $maxDay) {
                    $perDay = $maxDay;
                    $cell['warnings'][] = 'day_cap_binds';
                }

                $online = min($online, $perDay);

                if ($lines === 0) {
                    $cell['warnings'][] = 'crew_exceeds_washers';
                } elseif ($lines < $p->posts()) {
                    $cell['warnings'][] = 'bays_idle';
                } elseif ($perLine === 0) {
                    $cell['warnings'][] = 'longer_than_day';
                }

                $cell += ['open' => $open, 'lines' => $lines, 'cycle' => $cycle, 'perHour' => $perHour, 'perLine' => $perLine, 'perDay' => $perDay, 'online' => $online];
                $row['days'][$name] = $cell;
            }

            $row['warnings'] = array_keys($svcWarn);
            $services[] = $row;
        }

        $weekdays = [];

        foreach ($order as $name) {
            $d = $ref[$name];
            $h = $p->hoursFor($d);
            $weekdays[] = ['day' => $name, 'closed' => !$h, 'open' => $h ? $h[1] - $h[0] : 0, 'washers' => $p->washersFor($d), 'lines' => $p->linesFor($d), 'capacityMinutes' => $p->dayCapacityMinutes($d)];
        }

        return [
            'posts' => $p->posts(), 'washers' => $p->washers(), 'crewDefault' => $cd, 'buffer' => $buffer, 'sharePercent' => $share,
            'maxPerDay' => $maxDay, 'maxPerHour' => $maxHour, 'weekdays' => $weekdays, 'services' => $services, 'warnings' => array_keys($global),
        ];
    }

    // ------------------------------------------------------------------ calendar

    /**
     * Days from..to (local "Y-m-d", inclusive). Up to DETAIL_DAYS days (and not $summary): per hour capacity / booked / free / load and the orders.
     * More: per day totals only. Capacity is the Planner's, orders are everything but Cancelled.
     */
    public function range(string $from, string $to, bool $summary = false): array
    {
        $tz = $this->planner->tz();
        $utc = new \DateTimeZone('UTC');
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);

        if (!$a || !$b || $a->format('Y-m-d') !== $from || $b->format('Y-m-d') !== $to || $b < $a) {
            throw new \Espo\Core\Exceptions\BadRequest('from / to must be dates YYYY-MM-DD, from <= to.');
        }

        $count = (int) $a->diff($b)->days + 1;

        if ($count > self::MAX_DAYS) {
            throw new \Espo\Core\Exceptions\BadRequest('The range is limited to ' . self::MAX_DAYS . ' days.');
        }

        $detail = !$summary && $count <= self::DETAIL_DAYS;
        $rows = $this->planner->orderRows($a->setTimezone($utc), $b->modify('+1 day')->setTimezone($utc), self::COUNTED);
        $byDay = [];

        foreach ($rows as $r) {
            $byDay[$r['start']->setTimezone($tz)->format('Y-m-d')][] = $r;
        }

        $names = $detail ? $this->names($rows) : [];
        $buffer = $this->planner->buffer();
        $share = $this->planner->onlineSharePercent();
        $maxDay = $this->planner->maxPerDay();
        $days = [];
        $orders = [];

        for ($i = 0; $i < $count; $i++) {
            $d = $a->modify('+' . $i . ' days');
            $key = $d->format('Y-m-d');
            $h = $this->planner->hoursFor($d);
            $list = $byDay[$key] ?? [];
            $cap = $this->planner->dayCapacityMinutes($d);
            $load = $this->planner->loadOf($list);
            $booked = (int) round($load['minutes']);
            $overrides = 0;

            foreach ($list as $r) {
                $overrides += $r['entity']->get('capacityOverride') ? 1 : 0;
            }

            $day = [
                'date' => $key, 'dow' => (int) $d->format('w'), 'closed' => !$h,
                'open' => $h ? $h[0] : null, 'close' => $h ? $h[1] : null,
                'washers' => $this->planner->washersFor($d), 'lines' => $this->planner->linesFor($d),
                'capacityMinutes' => $cap, 'bookedMinutes' => $booked, 'cars' => $load['cars'],
                'loadPct' => $cap > 0 ? (int) round(100 * $load['minutes'] / $cap) : ($load['cars'] > 0 ? 100 : 0),
                // the online form closes at its share of the day; staff at the whole of it
                'onlineLimitMinutes' => (int) floor($cap * $share / 100),
                'onlineFull' => (bool) ($h && $cap > 0 && $load['minutes'] >= $cap * $share / 100 - 1e-6),
                'full' => (bool) ($h && (($cap > 0 && $load['minutes'] >= $cap - 1e-6) || ($maxDay > 0 && $load['cars'] >= $maxDay))),
                'over' => $load['minutes'] > $cap + 1e-6 || ($maxDay > 0 && $load['cars'] > $maxDay) || $overrides > 0,
                'overrides' => $overrides,
            ];

            if ($detail) {
                $day['hours'] = $h ? $this->hours($d, $h, $list, $buffer) : [];
                $day['maxCars'] = $maxDay;

                foreach ($list as $r) {
                    $orders[] = $this->orderRow($r, $names, $tz);
                }
            }

            $days[] = $day;
        }

        return [
            'from' => $from, 'to' => $to, 'detail' => $detail, 'tz' => $tz->getName(),
            'today' => $this->planner->now()->setTimezone($tz)->format('Y-m-d'),
            'slotMinutes' => $this->planner->slotMinutes(), 'posts' => $this->planner->posts(),
            'sharePercent' => $share, 'maxPerDay' => $maxDay, 'days' => $days, 'orders' => $orders,
        ];
    }

    /** @param array{0:int,1:int} $h */
    private function hours(\DateTimeImmutable $day, array $h, array $list, int $buffer): array
    {
        $utc = new \DateTimeZone('UTC');
        $cd = $this->planner->crewDefault();
        $lines = $this->planner->linesFor($day);
        $busy = [];

        foreach ($list as $r) {
            $busy[] = ['start' => $r['start'], 'end' => $r['start']->modify('+' . ($r['minutes'] + $buffer) . ' minutes'), 'crew' => $r['crew']];
        }

        $out = [];

        for ($hr = intdiv($h[0], 60); $hr < (int) ceil($h[1] / 60); $hr++) {
            $w0 = $day->setTime($hr, 0)->setTimezone($utc);
            $w1 = $w0->modify('+1 hour');
            $in = array_values(array_filter($busy, fn(array $b) => $b['end'] > $w0 && $b['start'] < $w1));
            [, $crew] = $this->planner->peak($in, $w0, $w1);
            $used = round($crew / $cd, 1);

            $out[] = [
                'h' => $hr, 'capacity' => $lines, 'booked' => $used, 'cars' => count($in), 'free' => max(0, round($lines - $used, 1)),
                'loadPct' => $lines > 0 ? (int) round(100 * $used / $lines) : ($used > 0 ? 100 : 0),
                'over' => $used > $lines + 1e-6,
            ];
        }

        return $out;
    }

    /** @return array{vehicle: array<string,string>, service: array<string,string>} */
    private function names(array $rows): array
    {
        $vIds = [];

        foreach ($rows as $r) {
            if ($r['entity']->get('vehicleId')) {
                $vIds[$r['entity']->get('vehicleId')] = true;
            }
        }

        $veh = [];

        foreach (array_chunk(array_keys($vIds), 200) as $chunk) {
            foreach ($this->em->getRDBRepository('Vehicle')->where(['id' => $chunk])->find() as $v) {
                $veh[$v->getId()] = (string) $v->get('name');
            }
        }

        $svc = [];

        foreach ($this->em->getRDBRepository('WashService')->find() as $s) {
            $svc[$s->getId()] = (string) $s->get('name');
        }

        return ['vehicle' => $veh, 'service' => $svc];
    }

    private function orderRow(array $r, array $names, \DateTimeZone $tz): array
    {
        $o = $r['entity'];
        $local = $r['start']->setTimezone($tz);
        $note = (string) $o->get('reservationNote');
        $source = (string) $o->get('source');

        return [
            'id' => $r['id'], 'number' => (string) $o->get('name'),
            'plate' => $names['vehicle'][$o->get('vehicleId')] ?? '', 'customer' => (string) $o->get('customerName'),
            'service' => $names['service'][$o->get('serviceId')] ?? '',
            'start' => $r['start']->format('Y-m-d H:i:s'), 'end' => $r['start']->modify('+' . $r['minutes'] . ' minutes')->format('Y-m-d H:i:s'),
            'date' => $local->format('Y-m-d'), 'startMin' => (int) $local->format('G') * 60 + (int) $local->format('i'), 'minutes' => $r['minutes'],
            'status' => $r['status'], 'post' => $r['post'], 'noShow' => (bool) $o->get('noShow'), 'capacityOverride' => (bool) $o->get('capacityOverride'),
            'crew' => $r['crew'],
            'source' => $source === 'Website' ? (str_starts_with($note, '[chat]') ? 'chat' : 'online') : 'staff',
        ];
    }
}
