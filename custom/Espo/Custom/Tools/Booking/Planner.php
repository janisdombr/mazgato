<?php

namespace Espo\Custom\Tools\Booking;

use Espo\Core\Utils\Config;
use Espo\ORM\EntityManager;

/**
 * The ONE place that knows when the wash can take a car: working hours per weekday, closed
 * dates, slot grid, bays (posts), cars-per-hour cap, buffer between cars, service duration.
 * Used by the public slot list, the public create, the reservation hook and the day schedule,
 * so the four can never disagree.
 *
 * All times inside are UTC DateTimeImmutable; "local" means Settings → timeZone. A booking
 * occupies [start, start + duration + buffer). A booking needs a free bay for the whole span.
 */
class Planner
{
    public const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    public const ACTIVE = ['Reserved', 'New', 'In Progress'];

    /** @var array<string, mixed> unsaved settings to preview (the calculator on the settings page); empty in normal use */
    private array $over = [];
    /** @var array<string, array{crew:int,minutes:int}> */
    private array $svcCache = [];

    public function __construct(private EntityManager $em, private Config $config) {}

    /** The same planner reading some settings from $values (null/'' = unset) instead of the stored config. Never persisted. */
    public function withOverrides(array $values): static
    {
        $c = clone $this;
        $c->over = $values;
        $c->svcCache = [];

        return $c;
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->over) ? ($this->over[$key] ?? $default) : $this->config->get($key, $default);
    }

    public function tz(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->cfg('timeZone', 'UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function int(string $k, int $d): int
    {
        $v = $this->cfg($k);

        return $v === null || $v === '' ? $d : (int) $v;
    }

    public function slotMinutes(): int { return max(5, $this->int('bookingSlotMinutes', 15)); }
    public function posts(): int { return max(1, $this->int('bookingPosts', 2)); }
    public function maxPerHour(): int { return max(0, $this->int('bookingMaxCarsPerHour', 0)); }
    public function buffer(): int { return max(0, $this->int('bookingBufferMinutes', 5)); }
    public function lead(): int { return max(0, $this->int('bookingMinLeadMinutes', 60)); }
    public function horizonDays(): int { return max(1, $this->int('bookingHorizonDays', 30)); }
    public function pastGrace(): int { return 15; }

    /** Empty setting = the default hours; 'closed' (or '-') = shut that weekday. @return ?array{0:int,1:int} minutes from local midnight [open, close), null = closed */
    public function hoursFor(\DateTimeImmutable $local): ?array
    {
        $date = $local->format('Y-m-d');

        foreach (preg_split('/\R/', (string) $this->cfg('bookingClosedDates', '')) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s*[-–:]\s*(\d{4}-\d{2}-\d{2}))?/', $line, $m)) {
                $to = $m[2] ?? $m[1];

                if ($date >= $m[1] && $date <= $to) {
                    return null;
                }
            }
        }

        $key = 'bookingHours' . self::DAYS[(int) $local->format('w')];
        $raw = $this->cfg($key);
        $raw = trim((string) $raw);

        if ($raw === '') {
            $raw = $this->defaultHours((int) $local->format('w'));
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $raw, $m)) {
            return null;
        }

        $o = (int) $m[1] * 60 + (int) $m[2];
        $c = (int) $m[3] * 60 + (int) $m[4];

        return $c > $o ? [$o, $c] : null;
    }

    private function defaultHours(int $dow): string
    {
        return $dow === 0 ? '10:00-18:00' : ($dow === 6 ? '09:00-18:00' : '08:00-20:00');
    }

    /** Minutes the services occupy: base + add-ons; 30 when no duration is configured. */
    public function duration(?string $serviceId, array $addonIds = []): int
    {
        $sum = 0;
        $ids = array_filter(array_merge([$serviceId], $addonIds));

        foreach ($ids as $id) {
            $s = $this->em->getEntityById('WashService', (string) $id);
            $sum += $s ? (int) $s->get('durationMinutes') : 0;
        }

        return $sum > 0 ? $sum : 30;
    }

    /** Duration of an existing order. */
    public function orderDuration(\Espo\ORM\Entity $o): int
    {
        if ((int) $o->get('plannedMinutes') > 0) {
            return (int) $o->get('plannedMinutes');
        }

        $addons = [];

        if ($o->get('id')) {
            foreach ($this->em->getRDBRepository('WashOrder')->getRelation($o, 'addons')->find() as $a) {
                $addons[] = $a->getId();
            }
        }

        return $this->duration($o->get('serviceId'), $addons);
    }

    // ------------------------------------------------------------------ labour model and day capacity

    /** Washers on duty (unset = one per bay, so an install that never heard of washers behaves as before). */
    public function washers(): int { return max(0, $this->int('bookingWashers', $this->posts())); }
    public function crewDefault(): int { return max(1, $this->int('bookingCrewDefault', 1)); }
    /** Share (0-100) of the computed day capacity that ONLINE bookings (form, chat) may use. */
    public function onlineSharePercent(): int { return min(100, max(0, $this->int('bookingDayCapacityPercent', 100))); }
    /** Hard cap on cars per day (0 = only the computed capacity limits). */
    public function maxPerDay(): int { return max(0, $this->int('bookingMaxCarsPerDay', 0)); }

    /** @return array<string, int> weekday ('Mon'..'Sun') => washers, from "Sat=4, Sun=4" */
    public function washersByWeekday(): array
    {
        $out = [];

        foreach (preg_split('/[,;\n]+/', (string) $this->cfg('bookingWashersByWeekday', '')) ?: [] as $part) {
            if (preg_match('/^\s*(mon|tue|wed|thu|fri|sat|sun)[a-z]*\s*[=:]\s*(\d{1,3})\s*$/i', $part, $m)) {
                $out[ucfirst(strtolower($m[1]))] = (int) $m[2];
            }
        }

        return $out;
    }

    /** Washers on duty on the weekday of $local. */
    public function washersFor(\DateTimeImmutable $local): int
    {
        return $this->washersByWeekday()[self::DAYS[(int) $local->format('w')]] ?? $this->washers();
    }

    /** Lines (cars washed at the same time) on a day for cars needing $crew washers: bays or washers/crew, whichever is fewer. */
    public function linesFor(\DateTimeImmutable $local, ?int $crew = null): int
    {
        $crew = max(1, $crew ?? $this->crewDefault());

        return max(0, min($this->posts(), intdiv($this->washersFor($local), $crew)));
    }

    /** @return array{crew:int,minutes:int} a service's crew (empty = the default) and its own duration, cached */
    private function svc(?string $id): array
    {
        $id = (string) $id;

        if ($id === '') {
            return ['crew' => $this->crewDefault(), 'minutes' => 0];
        }

        if (!isset($this->svcCache[$id])) {
            $s = $this->em->getEntityById('WashService', $id);
            $crew = $s ? (int) $s->get('crewSize') : 0;
            $this->svcCache[$id] = ['crew' => $crew > 0 ? $crew : $this->crewDefault(), 'minutes' => $s ? (int) $s->get('durationMinutes') : 0];
        }

        return $this->svcCache[$id];
    }

    /** Washers one car of this (base) service needs. Add-ons do not change the crew. */
    public function crewFor(?string $serviceId): int { return $this->svc($serviceId)['crew']; }

    /** Opening minutes of the day (0 = closed). */
    public function openMinutes(\DateTimeImmutable $local): int
    {
        $h = $this->hoursFor($local);

        return $h ? $h[1] - $h[0] : 0;
    }

    /** Line-minutes the day can give: lines (default crew) x open minutes. */
    public function dayCapacityMinutes(\DateTimeImmutable $local): int
    {
        return $this->linesFor($local) * $this->openMinutes($local);
    }

    /** Line-minutes one car takes out of the day: (duration + buffer), times its crew relative to the default crew. */
    public function weight(int $minutes, int $crew): float
    {
        return ($minutes + $this->buffer()) * $crew / $this->crewDefault();
    }

    /**
     * Cars of one LOCAL day that use its capacity: Reserved, New, In Progress and Completed (Cancelled never). One query.
     * @return array<int, array{id:string,start:\DateTimeImmutable,minutes:int,crew:int,status:string}>
     */
    public function dayOrders(\DateTimeImmutable $localDay): array
    {
        $from = $localDay->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $rows = $this->orderRows($from, $localDay->setTime(0, 0)->modify('+1 day')->setTimezone(new \DateTimeZone('UTC')), ['Reserved', 'New', 'In Progress', 'Completed']);

        return array_map(fn(array $r) => ['id' => $r['id'], 'start' => $r['start'], 'minutes' => $r['minutes'], 'crew' => $r['crew'], 'status' => $r['status']], $rows);
    }

    /**
     * Orders STARTING in [from, to) with the minutes they occupy and the crew they need. The add-on minutes of orders that carry
     * no `plannedMinutes` come from ONE aggregate query, not one query per order.
     * @param string[] $statuses
     * @return array<int, array{id:string,start:\DateTimeImmutable,minutes:int,crew:int,status:string,post:?int,entity:\Espo\ORM\Entity}>
     */
    public function orderRows(\DateTimeImmutable $from, \DateTimeImmutable $to, array $statuses): array
    {
        $orders = $this->em->getRDBRepository('WashOrder')->where([
            'deleted' => false,
            'status' => $statuses,
            'orderDateTime>=' => $from->format('Y-m-d H:i:s'),
            'orderDateTime<' => $to->format('Y-m-d H:i:s'),
        ])->order('orderDateTime')->find();
        $list = [];
        $need = [];

        foreach ($orders as $o) {
            $list[] = $o;

            if ((int) $o->get('plannedMinutes') <= 0) {
                $need[] = $o->getId();
            }
        }

        $extra = [];

        if ($need) {
            $in = implode(',', array_map(fn($id) => $this->em->getPDO()->quote($id), $need));
            $q = $this->em->getPDO()->query("SELECT j.wash_order_id, COALESCE(SUM(s.duration_minutes), 0) FROM wash_order_addon j JOIN wash_service s ON s.id = j.wash_service_id AND s.deleted = 0 WHERE j.deleted = 0 AND j.wash_order_id IN ($in) GROUP BY j.wash_order_id");

            foreach ($q ? $q->fetchAll(\PDO::FETCH_NUM) : [] as [$oid, $sum]) {
                $extra[$oid] = (int) $sum;
            }
        }

        $out = [];

        foreach ($list as $o) {
            $planned = (int) $o->get('plannedMinutes');
            $minutes = $planned > 0 ? $planned : ($this->svc($o->get('serviceId'))['minutes'] + ($extra[$o->getId()] ?? 0));
            $out[] = [
                'id' => $o->getId(),
                'start' => new \DateTimeImmutable((string) $o->get('orderDateTime'), new \DateTimeZone('UTC')),
                'minutes' => $minutes > 0 ? $minutes : 30,
                'crew' => $this->crewFor($o->get('serviceId')),
                'status' => (string) $o->get('status'),
                'post' => $o->get('post') ? (int) $o->get('post') : null,
                'entity' => $o,
            ];
        }

        return $out;
    }

    /** Sum of the line-minutes and the count of the cars in $rows (dayOrders), leaving out one order (the one being moved). */
    public function loadOf(array $rows, ?string $exclude = null): array
    {
        $min = 0.0;
        $n = 0;

        foreach ($rows as $r) {
            if ($exclude && $r['id'] === $exclude) {
                continue;
            }

            $min += $this->weight($r['minutes'], $r['crew']);
            $n++;
        }

        return ['minutes' => $min, 'cars' => $n];
    }

    /**
     * Does one more car still fit into the DAY? '' = yes, 'day_full' = no. Online bookings may use only the share
     * `bookingDayCapacityPercent` of the computed capacity; staff reservations the whole of it. A closed day is not judged here
     * (the hours are checked by the public paths; staff may squeeze a car in, as before).
     * @param ?array $rows dayOrders() of the day, when the caller has them already
     */
    public function dayCheck(\DateTimeImmutable $localDay, int $minutes, int $crew, bool $online, ?string $exclude = null, ?array $rows = null): string
    {
        $localDay = $localDay->setTime(0, 0);

        if (!$this->hoursFor($localDay)) {
            return '';
        }

        $rows ??= $this->dayOrders($localDay);
        $load = $this->loadOf($rows, $exclude);
        $limit = $this->dayCapacityMinutes($localDay) * ($online ? $this->onlineSharePercent() : 100) / 100;

        if ($load['minutes'] + $this->weight($minutes, $crew) > $limit + 1e-6) {
            return 'day_full';
        }

        $max = $this->maxPerDay();

        if ($max > 0 && $load['cars'] + 1 > $max) {
            return 'day_full';
        }

        return '';
    }

    /**
     * Orders whose span touches [from, to).
     * @return array<int, array{id:string,start:\DateTimeImmutable,end:\DateTimeImmutable,post:?int,crew:int}>
     */
    public function busy(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $exclude, bool $includeActive): array
    {
        $statuses = $includeActive ? self::ACTIVE : ['Reserved'];
        $rows = $this->orderRows($from->modify('-1 day'), $to, $statuses);
        $out = [];
        $buf = $this->buffer();

        foreach ($rows as $r) {
            if ($exclude && $r['id'] === $exclude) {
                continue;
            }

            $e = $r['start']->modify('+' . ($r['minutes'] + $buf) . ' minutes');

            if ($e > $from && $r['start'] < $to) {
                $out[] = ['id' => $r['id'], 'start' => $r['start'], 'end' => $e, 'post' => $r['post'], 'crew' => $r['crew']];
            }
        }

        return $out;
    }

    /**
     * The part of an already loaded busy list that touches [from, to) - the same test busy() applies, without a query.
     * @param array<int, array{id:string,start:\DateTimeImmutable,end:\DateTimeImmutable,post:?int,crew:int}> $loaded
     */
    private function within(array $loaded, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_values(array_filter($loaded, fn(array $b) => $b['end'] > $from && $b['start'] < $to));
    }

    /**
     * Can a car of $duration minutes start at $start?
     *
     * Two limits: AT ANY MINUTE of its span the cars on the premises (with this one) are at most the bays and the sum of their
     * crews at most the washers of that day ('full'), and the DAY still has room ('day_full').
     * $opt: crew (washers this car needs, default = bookingCrewDefault), online (bool: judged against the online share of the day),
     * day (dayOrders() of the day, preloaded), skipDay (bool).
     *
     * @return array{ok:bool,post:int,reason:string}
     */
    public function check(\DateTimeImmutable $start, int $duration, ?string $exclude = null, bool $includeActive = true, ?array $preloaded = null, array $opt = []): array
    {
        $end = $start->modify('+' . ($duration + $this->buffer()) . ' minutes');
        $busy = $preloaded === null ? $this->busy($start, $end, $exclude, $includeActive) : $this->within($preloaded, $start, $end);
        $posts = $this->posts();
        $crew = max(1, (int) ($opt['crew'] ?? $this->crewDefault()));
        $local = $start->setTimezone($this->tz());
        $washers = $this->washersFor($local);

        if ($crew > $washers || $posts < 1) {
            return ['ok' => false, 'post' => 0, 'reason' => 'full'];
        }

        // Not every overlapping car overlaps the others; a bay (and a washer) is free if at most posts-1 cars / washers-crew washers
        // are busy AT ANY INSTANT. Sweep the span.
        [$peakCars, $peakCrew] = $this->peak($busy, $start, $end);

        if ($peakCars + 1 > $posts || $peakCrew + $crew > $washers) {
            return ['ok' => false, 'post' => 0, 'reason' => 'full'];
        }

        $max = $this->maxPerHour();

        if ($max > 0) {
            $h0 = $local->setTime((int) $local->format('G'), 0)->setTimezone(new \DateTimeZone('UTC'));
            $inHour = 0;

            $hourBusy = $preloaded === null
                ? $this->busy($h0, $h0->modify('+1 hour'), $exclude, $includeActive)
                : $this->within($preloaded, $h0, $h0->modify('+1 hour'));

            foreach ($hourBusy as $b) {
                if ($b['start'] >= $h0 && $b['start'] < $h0->modify('+1 hour')) {
                    $inHour++;
                }
            }

            if ($inHour >= $max) {
                return ['ok' => false, 'post' => 0, 'reason' => 'hour-cap'];
            }
        }

        if (empty($opt['skipDay']) && $this->dayCheck($local, $duration, $crew, !empty($opt['online']), $exclude, $opt['day'] ?? null) !== '') {
            return ['ok' => false, 'post' => 0, 'reason' => 'day_full'];
        }

        $used = [];

        foreach ($busy as $b) {
            if ($b['post']) {
                $used[$b['post']] = true;
            }
        }

        $post = 1;

        while (isset($used[$post]) && $post < $posts) {
            $post++;
        }

        return ['ok' => true, 'post' => $post, 'reason' => ''];
    }

    /**
     * Peak simultaneous cars and peak simultaneous washers (sum of crews) inside [from, to).
     * @return array{0:int,1:int}
     */
    public function peak(array $busy, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $pts = [$from->getTimestamp()];

        foreach ($busy as $b) {
            foreach ([$b['start'], $b['end']] as $t) {
                if ($t > $from && $t < $to) {
                    $pts[] = $t->getTimestamp();
                }
            }
        }

        $peak = 0;
        $peakCrew = 0;

        foreach ($pts as $p) {
            $n = 0;
            $c = 0;

            foreach ($busy as $b) {
                if ($b['start']->getTimestamp() <= $p && $b['end']->getTimestamp() > $p) {
                    $n++;
                    $c += (int) ($b['crew'] ?? 1);
                }
            }

            $peak = max($peak, $n);
            $peakCrew = max($peakCrew, $c);
        }

        return [$peak, $peakCrew];
    }

    /**
     * Free start times on a local date for a job of $duration minutes.
     * $opt as in check(): crew, online (default TRUE here: this is the list the public form and the chat show).
     * @return array<int, array{time:string,free:int}>
     */
    public function slots(string $date, int $duration, ?string $exclude = null, array $opt = []): array
    {
        $tz = $this->tz();
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);

        if (!$day) {
            return [];
        }

        $hours = $this->hoursFor($day);

        if (!$hours) {
            return [];
        }

        $opt += ['crew' => $this->crewDefault(), 'online' => true];
        $crew = max(1, (int) $opt['crew']);

        // The DAY is judged once: a day that is full offers no start at all.
        $dayRows = $this->dayOrders($day);

        if ($this->dayCheck($day, $duration, $crew, (bool) $opt['online'], $exclude, $dayRows) !== '') {
            return [];
        }

        $now = $this->now();
        $earliest = $now->modify('+' . $this->lead() . ' minutes');
        $out = [];
        $step = $this->slotMinutes();
        $lines = $this->linesFor($day, $crew);

        // ONE query for the whole day (an hour of slack before it for the per-hour cap, the longest possible job plus an hour of slack after it);
        // every slot is then judged in memory. It used to ask the database twice per slot - each time loading and sizing every
        // order of the surrounding day - which made a three-day look-up take over 20 seconds on a busy day.
        $dayStart = $day->setTimezone(new \DateTimeZone('UTC'))->modify('-1 hour');
        $dayEnd = $day->modify('+' . ($hours[1] + $duration + $this->buffer() + 60) . ' minutes')->setTimezone(new \DateTimeZone('UTC'));
        $loaded = $this->busy($dayStart, $dayEnd, $exclude, true);

        for ($m = $hours[0]; $m + $duration <= $hours[1]; $m += $step) {
            $local = $day->modify('+' . $m . ' minutes');
            $start = $local->setTimezone(new \DateTimeZone('UTC'));

            if ($start < $earliest) {
                continue;
            }

            $r = $this->check($start, $duration, $exclude, true, $loaded, ['crew' => $crew, 'skipDay' => true]);

            if ($r['ok']) {
                $out[] = ['time' => $local->format('H:i'), 'free' => max(1, $lines - count($this->within($loaded, $start, $start->modify('+' . ($duration + $this->buffer()) . ' minutes'))))];
            }
        }

        return $out;
    }

    /** Is [local date, time] a legal slot start (on the grid, inside hours, in the window)? */
    public function onGrid(\DateTimeImmutable $startUtc, int $duration): bool
    {
        $local = $startUtc->setTimezone($this->tz());
        $hours = $this->hoursFor($local);

        if (!$hours) {
            return false;
        }

        $m = (int) $local->format('G') * 60 + (int) $local->format('i');

        return $m >= $hours[0] && $m + $duration <= $hours[1] && ($m - $hours[0]) % $this->slotMinutes() === 0;
    }
}
