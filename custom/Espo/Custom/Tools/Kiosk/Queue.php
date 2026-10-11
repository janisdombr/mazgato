<?php

namespace Espo\Custom\Tools\Kiosk;

use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Read models for the tablet. Deliberately thin: plate, car, services, works, who is on
 * them. No customer name, phone, price or payment — a wall-mounted screen in a wash bay is
 * not where those belong, and the worker has no use for them.
 */
class Queue
{
    /** Orders still worth showing; older stuck ones are an operator's job in the CRM. */
    private const STALE_HOURS = 36;

    private const ACTIVE = ['Reserved', 'New', 'In Progress'];

    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Entity $employee): array
    {
        $now = gmdate('Y-m-d H:i:s');
        [$dayStart, $dayEnd] = $this->todayBoundsUtc();
        $fresh = gmdate('Y-m-d H:i:s', time() - self::STALE_HOURS * 3600);

        $orders = $this->entityManager->getRDBRepository('WashOrder')
            ->where([
                'deleted' => false,
                'OR' => [
                    [
                        'status' => ['New', 'In Progress'],
                        'orderDateTime>=' => $fresh,
                    ],
                    [
                        // An order made outside the intake form can lack orderDateTime.
                        'status' => ['New', 'In Progress'],
                        'orderDateTime' => null,
                        'createdAt>=' => $fresh,
                    ],
                    [
                        'status' => 'Reserved',
                        'orderDateTime>=' => $dayStart,
                        'orderDateTime<' => $dayEnd,
                    ],
                ],
            ])
            ->order('orderDateTime', 'ASC')
            ->limit(0, 60)
            ->find();

        $mine = [];
        $onRamp = [];
        $waiting = [];
        $reserved = [];

        foreach ($orders as $order) {
            $card = $this->card($order, $employee);

            // "My car" = any active order I am on (taken but not yet started included);
            // the other lists hold everybody else's, so a car appears exactly once.
            if ($card['mine']) {
                $mine[] = $card;

                continue;
            }

            match ($order->get('status')) {
                'In Progress' => $onRamp[] = $card,
                'New' => $waiting[] = $card,
                default => $reserved[] = $card,
            };
        }

        return [
            'serverTime' => $now,
            'mine' => $mine,
            'onRamp' => $onRamp,
            'waiting' => $waiting,
            'reserved' => $reserved,
            'today' => $this->todaySummary($employee, $dayStart, $dayEnd),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(Entity $order, Entity $employee, bool $withPhotos = false): array
    {
        $vehicle = $order->get('vehicleId')
            ? $this->entityManager->getEntityById('Vehicle', (string) $order->get('vehicleId'))
            : null;

        $service = $order->get('serviceId')
            ? $this->entityManager->getEntityById('WashService', (string) $order->get('serviceId'))
            : null;

        $addons = [];
        $planned = $service ? (int) $service->get('durationMinutes') : 0;

        foreach ($order->getLinkMultipleIdList('addons') as $addonId) {
            $addon = $this->entityManager->getEntityById('WashService', $addonId);

            if ($addon) {
                $addons[] = (string) $addon->get('name');
                $planned += (int) $addon->get('durationMinutes');
            }
        }

        $lines = $this->lines($order, $employee);
        $free = count(array_filter($lines, fn ($l) => $l['free']));
        $mineCount = count(array_filter($lines, fn ($l) => $l['mine']));
        $done = count(array_filter($lines, fn ($l) => $l['done']));
        $status = (string) $order->get('status');
        $active = in_array($status, self::ACTIVE, true);
        $assigned = $mineCount > 0 || !$lines;
        $roleId = $employee->get('workRoleId');
        $takeable = array_filter($lines, fn ($l) => $l['free'] && (!$roleId || $l['roleId'] === $roleId));

        $card = [
            'id' => $order->getId(),
            'number' => (string) $order->get('name'),
            'plate' => $vehicle ? (string) $vehicle->get('name') : '',
            'car' => trim(($vehicle ? (string) $vehicle->get('brand') : '') . ' ' . ($vehicle ? (string) $vehicle->get('model') : '')),
            'status' => $status,
            'orderDateTime' => $order->get('orderDateTime'),
            'startedAt' => $order->get('startedAt'),
            'finishedAt' => $order->get('finishedAt'),
            'service' => $service ? (string) $service->get('name') : '',
            'addons' => $addons,
            'plannedMinutes' => $planned,
            'lines' => $lines,
            'total' => count($lines),
            'doneCount' => $done,
            'freeCount' => $free,
            'mine' => $mineCount > 0,
            'canTake' => $active && count($takeable) > 0,
            'canStart' => $active && $status !== 'In Progress' && $assigned,
            'canFinish' => $status === 'In Progress' && $assigned,
        ];

        if ($withPhotos) {
            $card['photos'] = $this->photoCounts($order->getId());
        }

        return $card;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(Entity $order, Entity $employee): array
    {
        $rows = $this->entityManager->getRDBRepository('OrderWork')
            ->where(['washOrderId' => $order->getId(), 'deleted' => false])
            ->order('createdAt', 'ASC')
            ->find();

        $names = [];
        $out = [];

        foreach ($rows as $row) {
            $empId = $row->get('employeeId');

            if ($empId && !isset($names[$empId])) {
                $e = $this->entityManager->getEntityById('Employee', $empId);
                $names[$empId] = $e ? (string) $e->get('name') : '';
            }

            $out[] = [
                'id' => $row->getId(),
                'work' => (string) $row->get('name'),
                'roleId' => $row->get('roleId'),
                'employeeId' => $empId,
                'employeeName' => $empId ? $names[$empId] : null,
                'mine' => $empId === $employee->getId(),
                'free' => !$empId,
                'startedAt' => $row->get('startedAt'),
                'done' => (bool) $row->get('doneAt'),
                'doneAt' => $row->get('doneAt'),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public function photoCounts(string $orderId): array
    {
        $counts = ['total' => 0];

        $photos = $this->entityManager->getRDBRepository('OrderPhoto')
            ->select(['id', 'kind'])
            ->where(['washOrderId' => $orderId, 'deleted' => false])
            ->find();

        foreach ($photos as $photo) {
            $kind = (string) $photo->get('kind');
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            $counts['total']++;
        }

        return $counts;
    }

    /**
     * The worker's day in three honest numbers, from the single dataset: cars they worked
     * on, works they closed, and what those works are worth (OrderWork.amount).
     *
     * @return array<string, mixed>
     */
    public function todaySummary(Entity $employee, ?string $from = null, ?string $to = null): array
    {
        if (!$from || !$to) {
            [$from, $to] = $this->todayBoundsUtc();
        }

        $lines = $this->entityManager->getRDBRepository('OrderWork')
            ->where([
                'employeeId' => $employee->getId(),
                'doneAt>=' => $from,
                'doneAt<' => $to,
                'deleted' => false,
            ])
            ->find();

        $orders = [];
        $amount = 0.0;
        $works = 0;

        foreach ($lines as $line) {
            $orders[$line->get('washOrderId')] = true;
            $amount += (float) $line->get('amount');
            $works++;
        }

        return [
            'cars' => count($orders),
            'works' => $works,
            'earned' => round($amount, 2),
        ];
    }

    /** @return array{0: string, 1: string} local midnight to midnight as UTC datetimes */
    public function todayBoundsUtc(): array
    {
        $tz = new \DateTimeZone((string) ($this->config->get('timeZone') ?: 'UTC'));
        $utc = new \DateTimeZone('UTC');
        $start = (new \DateTime('today', $tz));
        $end = (clone $start)->modify('+1 day');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}
