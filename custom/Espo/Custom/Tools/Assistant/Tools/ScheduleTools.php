<?php

namespace Espo\Custom\Tools\Assistant\Tools;

use Espo\Custom\Controllers\Booking;
use Espo\Custom\Tools\Assistant\InternalRequest;
use Espo\Custom\Tools\Assistant\Toolset;
use Espo\Custom\Tools\Assistant\Untrusted;

/** The day's plan (same data as the DaySchedule dashlet) and the service catalogue. */
class ScheduleTools extends Toolset
{
    public function specs(): array
    {
        return [
            [
                'name' => 'day_schedule',
                'description' => 'The schedule of one day: reservations and orders by time and bay, with plate, service, status. Same data as the Day schedule dashlet.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, local. Default: today.'],
                ], 'additionalProperties' => false],
            ],
            [
                'name' => 'services',
                'description' => 'The price list: active services with id, category (Base / Add-on), retail and corporate price, duration. Needed to prepare a reservation draft.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        return $name === 'day_schedule' ? $this->day($args) : ($name === 'services' ? $this->services() : $this->fail(404, 'unknown tool'));
    }

    private function day(array $args): array
    {
        if (!$this->ctx->canRead('WashOrder')) {
            return $this->denied('orders');
        }

        $date = trim((string) ($args['date'] ?? ''));

        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->fail(400, 'date must be YYYY-MM-DD.');
        }

        try {
            $r = json_decode((string) json_encode($this->ctx->factory->create(Booking::class)->postActionDay(new InternalRequest(['date' => $date]))), true) ?: [];
        } catch (\Throwable $e) {
            return $this->fail(400, 'invalid date');
        }

        $rows = [];

        foreach ((array) ($r['rows'] ?? []) as $x) {
            $o = $this->ctx->em->getEntityById('WashOrder', (string) $x['id']);

            if (!$o || !$this->ctx->canSee($o)) {
                continue;
            }

            $c = $o->get('customerId') ? $this->ctx->em->getEntityById('Customer', (string) $o->get('customerId')) : null;
            $rows[] = ['time' => $x['time'], 'minutes' => $x['minutes'], 'bay' => $x['post'], 'status' => $x['status'],
                'plate' => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $x['plate'])),
                'service' => Untrusted::clean((string) $x['service'], 50)]
                + ($c && $this->ctx->canSee($c) ? $this->ctx->customerRef($c) : []);
        }

        return [
            'source' => 'Booking day schedule (Day schedule dashlet)',
            'date' => $r['date'] ?? $date,
            'bays' => $r['posts'] ?? null,
            'open' => $r['open'] ?? null,
            'close' => $r['close'] ?? null,
            'bookings' => count($rows),
            'rows' => $rows,
        ];
    }

    private function services(): array
    {
        if (!$this->ctx->canRead('WashService')) {
            return $this->denied('services');
        }

        $rows = [];

        foreach ($this->ctx->em->getRDBRepository('WashService')->where(['isActive' => true, 'deleted' => false])->order('sortOrder')->find() as $s) {
            $rows[] = [
                'id' => $s->getId(), 'name' => Untrusted::clean((string) $s->get('name'), 60), 'category' => (string) $s->get('category'),
                'priceRetail' => round((float) $s->get('priceRetail'), 2), 'priceCorporate' => round((float) $s->get('priceCorporate'), 2),
                'minutes' => (int) $s->get('durationMinutes'),
            ];
        }

        return ['source' => 'WashService price list', 'currency' => 'EUR', 'services' => array_slice($rows, 0, 60)];
    }
}
