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

    public function __construct(private EntityManager $em, private Config $config) {}

    public function tz(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->config->get('timeZone', 'UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function int(string $k, int $d): int
    {
        $v = $this->config->get($k);

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

        foreach (preg_split('/\R/', (string) $this->config->get('bookingClosedDates', '')) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s*[-–:]\s*(\d{4}-\d{2}-\d{2}))?/', $line, $m)) {
                $to = $m[2] ?? $m[1];

                if ($date >= $m[1] && $date <= $to) {
                    return null;
                }
            }
        }

        $key = 'bookingHours' . self::DAYS[(int) $local->format('w')];
        $raw = $this->config->get($key);
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

    /**
     * Orders whose span touches [from, to).
     * @return array<int, array{id:string,start:\DateTimeImmutable,end:\DateTimeImmutable,post:?int}>
     */
    public function busy(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $exclude, bool $includeActive): array
    {
        $statuses = $includeActive ? self::ACTIVE : ['Reserved'];
        $q = $this->em->getRDBRepository('WashOrder')->where([
            'deleted' => false,
            'status' => $statuses,
            'orderDateTime>=' => $from->modify('-1 day')->format('Y-m-d H:i:s'),
            'orderDateTime<' => $to->format('Y-m-d H:i:s'),
        ]);
        $out = [];
        $buf = $this->buffer();

        foreach ($q->find() as $o) {
            if ($exclude && $o->getId() === $exclude) {
                continue;
            }

            $s = new \DateTimeImmutable((string) $o->get('orderDateTime'), new \DateTimeZone('UTC'));
            $e = $s->modify('+' . ($this->orderDuration($o) + $buf) . ' minutes');

            if ($e > $from && $s < $to) {
                $out[] = ['id' => $o->getId(), 'start' => $s, 'end' => $e, 'post' => $o->get('post') ? (int) $o->get('post') : null];
            }
        }

        return $out;
    }

    /**
     * Can a car of $duration minutes start at $start?
     * @return array{ok:bool,post:int,reason:string}
     */
    public function check(\DateTimeImmutable $start, int $duration, ?string $exclude = null, bool $includeActive = true): array
    {
        $end = $start->modify('+' . ($duration + $this->buffer()) . ' minutes');
        $busy = $this->busy($start, $end, $exclude, $includeActive);
        $posts = $this->posts();

        if (count($busy) >= $posts) {
            // Not every overlapping car overlaps the others; a bay is free if at most posts-1
            // overlap AT ANY INSTANT. Sweep the span.
            if ($this->peak($busy, $start, $end) >= $posts) {
                return ['ok' => false, 'post' => 0, 'reason' => 'full'];
            }
        }

        $max = $this->maxPerHour();

        if ($max > 0) {
            $local = $start->setTimezone($this->tz());
            $h0 = $local->setTime((int) $local->format('G'), 0)->setTimezone(new \DateTimeZone('UTC'));
            $inHour = 0;

            foreach ($this->busy($h0, $h0->modify('+1 hour'), $exclude, $includeActive) as $b) {
                if ($b['start'] >= $h0 && $b['start'] < $h0->modify('+1 hour')) {
                    $inHour++;
                }
            }

            if ($inHour >= $max) {
                return ['ok' => false, 'post' => 0, 'reason' => 'hour-cap'];
            }
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

    /** Peak number of simultaneous cars inside [from, to). */
    private function peak(array $busy, \DateTimeImmutable $from, \DateTimeImmutable $to): int
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

        foreach ($pts as $p) {
            $n = 0;

            foreach ($busy as $b) {
                if ($b['start']->getTimestamp() <= $p && $b['end']->getTimestamp() > $p) {
                    $n++;
                }
            }

            $peak = max($peak, $n);
        }

        return $peak;
    }

    /**
     * Free start times on a local date for a job of $duration minutes.
     * @return array<int, array{time:string,free:int}>
     */
    public function slots(string $date, int $duration, ?string $exclude = null): array
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

        $now = $this->now();
        $earliest = $now->modify('+' . $this->lead() . ' minutes');
        $out = [];
        $step = $this->slotMinutes();

        for ($m = $hours[0]; $m + $duration <= $hours[1]; $m += $step) {
            $local = $day->modify('+' . $m . ' minutes');
            $start = $local->setTimezone(new \DateTimeZone('UTC'));

            if ($start < $earliest) {
                continue;
            }

            $r = $this->check($start, $duration, $exclude, true);

            if ($r['ok']) {
                $out[] = ['time' => $local->format('H:i'), 'free' => max(1, $this->posts() - count($this->busy($start, $start->modify('+' . ($duration + $this->buffer()) . ' minutes'), $exclude, true)))];
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
