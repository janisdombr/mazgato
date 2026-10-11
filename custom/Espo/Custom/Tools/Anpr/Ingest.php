<?php

namespace Espo\Custom\Tools\Anpr;

use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Kiosk\Deny;
use Espo\Custom\Tools\Kiosk\PhotoStore;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * One plate event from an edge node -> a `PlateEvent` row and a decision.
 *
 *   idempotent   the pair (node, eventId) is unique; a resend (the node's offline queue) is
 *                answered with the SAME event and does nothing again - this also holds for a
 *                duplicate that was folded into another event (`dupIds`).
 *   de-duplicated the same plate on the same camera inside `anprDedupSeconds` is ONE event: the
 *                first keeps the card, its `seenCount` goes up.
 *   safe by default
 *                low confidence                      -> always Pending, a person decides;
 *                car already has an order on the board -> Matched to it, nothing is created;
 *                high confidence + known car + `anprAutoCreate` (OFF by default) + no active order
 *                                                    -> the only case an order is made unasked;
 *                everything else                     -> Pending ("a car at the entrance").
 *   untrusted input  the picture is decoded and re-encoded (EXIF/GPS and hidden payloads never
 *                stored), the plate is normalised like every vehicle, sizes are capped.
 */
class Ingest
{
    public function __construct(
        private EntityManager $entityManager,
        private Settings $settings,
        private Matcher $matcher,
        private PhotoStore $photos,
        private Media $media,
        private Orders $orders,
        private Log $log
    ) {}

    /**
     * @return array<string, mixed>
     * @throws Deny
     */
    public function handle(?Entity $node, stdClass $b, ?string $ip, string $sourceHint = 'Other'): array
    {
        $externalId = (string) ($b->eventId ?? '');

        if (!preg_match('/^[A-Za-z0-9:_.\-]{8,80}$/', $externalId)) {
            throw new Deny('event_id_required', 400);
        }

        // 1. a resend answers from what is already stored
        $known = $this->findByExternalId($node ? $node->getId() : null, $externalId);

        if ($known) {
            return $this->answer($known, ['replayed' => true]);
        }

        // 2. validate (nothing is stored before this passes)
        $camera = $this->camera($node, (string) ($b->camera ?? ''));
        $plateRaw = trim((string) ($b->plateRaw ?? $b->plate ?? ''));
        $plate = Matcher::normalize($plateRaw);

        if ($plateRaw === '' || strlen($plateRaw) > 30 || strlen($plate) < 2 || strlen($plate) > 12) {
            throw new Deny('plate_invalid', 422);
        }

        $confidence = $this->confidence($b->confidence ?? null);
        $takenAt = $this->time($b->takenAt ?? null);
        $direction = $this->direction((string) ($b->direction ?? ''), (string) $camera->get('role'));
        $source = $this->source((string) ($b->source ?? $sourceHint));

        $jpeg = null;
        $snap = (string) ($b->snapshotBase64 ?? '');

        if ($snap !== '') {
            $jpeg = $this->photos->jpegFromBase64($snap);
        }

        // 3. the same car, seen again
        $window = $this->settings->dedupSeconds();
        $twin = $this->entityManager->getRDBRepository('PlateEvent')
            ->where([
                'plate' => $plate,
                'cameraId' => $camera->getId(),
                'deleted' => false,
                'takenAt>=' => gmdate('Y-m-d H:i:s', strtotime($takenAt . ' UTC') - $window),
                'takenAt<=' => gmdate('Y-m-d H:i:s', strtotime($takenAt . ' UTC') + $window),
            ])
            ->order('takenAt', 'DESC')
            ->findOne();

        if ($twin) {
            $ids = array_filter(explode('|', (string) $twin->get('dupIds')));
            $ids[] = $externalId;
            $twin->set([
                'seenCount' => (int) $twin->get('seenCount') + 1,
                'lastSeenAt' => gmdate('Y-m-d H:i:s'),
                'dupIds' => '|' . implode('|', array_slice($ids, -40)) . '|',
            ]);
            $this->entityManager->saveEntity($twin, ['silent' => true]);

            return $this->answer($twin, ['duplicate' => true]);
        }

        // 4. store
        $match = $this->matcher->match($plate);
        $vehicle = $match['vehicle'];
        $active = $match['kind'] === Matcher::EXACT ? $this->matcher->activeOrder($vehicle) : null;
        $now = gmdate('Y-m-d H:i:s');

        $key = $jpeg !== null ? $this->media->store($jpeg) : null;

        $event = $this->entityManager->createEntity('PlateEvent', [
            'name' => $plate,
            'plateRaw' => $plateRaw,
            'plate' => $plate,
            'plateSuggested' => $match['suggested'],
            'matchKind' => $match['kind'],
            'confidence' => $confidence,
            'direction' => $direction,
            'source' => $source,
            'status' => 'Pending',
            'takenAt' => $takenAt,
            'receivedAt' => $now,
            'lastSeenAt' => $now,
            'seenCount' => 1,
            'externalId' => $externalId,
            'snapshotKey' => $key,
            'hasSnapshot' => $key !== null,
            'snapshotRetainUntil' => $key !== null ? gmdate('Y-m-d', time() + $this->settings->snapshotDays() * 86400) : null,
            'retainUntil' => gmdate('Y-m-d', time() + $this->settings->eventDays() * 86400),
            'cameraId' => $camera->getId(),
            'edgeNodeId' => $node ? $node->getId() : null,
            'vehicleId' => $match['kind'] === Matcher::EXACT ? $vehicle->getId() : null,
            'customerId' => $match['kind'] === Matcher::EXACT ? $vehicle->get('customerId') : null,
            'dupIds' => '|' . $externalId . '|',
        ], ['silent' => true]);

        // 5. decide
        $low = $confidence < $this->settings->minConfidence();
        $high = $confidence >= $this->settings->highConfidence();

        try {
            if ($active && !$low) {
                $this->attachToOrder($event, $active, $direction, $jpeg, $takenAt);
            } elseif (!$low && $high && $match['kind'] === Matcher::EXACT && !$active && $this->settings->autoCreate()) {
                $this->autoCreate($event, $vehicle, $jpeg, $takenAt);
            }
        } catch (\Throwable $e) {
            // The event is already stored and still Pending: a person decides. Never lose it.
            $this->log->warning('Anpr: automatic handling failed, the event stays Pending: ' . get_class($e) . ': ' . $e->getMessage());
        }

        return $this->answer($this->entityManager->getEntityById('PlateEvent', $event->getId()));
    }

    /** What the edge node is told: enough to log, nothing about the customer. */
    private function answer(Entity $event, array $extra = []): array
    {
        return $extra + [
            'ok' => true,
            'eventId' => $event->getId(),
            'status' => (string) $event->get('status'),
            'plate' => (string) $event->get('plate'),
        ];
    }

    private function findByExternalId(?string $nodeId, string $externalId): ?Entity
    {
        $repo = $this->entityManager->getRDBRepository('PlateEvent');

        $hit = $repo->where(['edgeNodeId' => $nodeId, 'externalId' => $externalId, 'deleted' => false])->findOne();

        return $hit ?: $repo->where(['edgeNodeId' => $nodeId, 'dupIds*' => '%|' . $externalId . '|%', 'deleted' => false])->findOne();
    }

    /** @throws Deny */
    private function camera(?Entity $node, string $code): Entity
    {
        $code = strtolower(trim($code));

        $camera = $code !== '' ? $this->entityManager->getRDBRepository('Camera')->where(['code' => $code, 'deleted' => false])->findOne() : null;

        if (!$camera || !$camera->get('isActive')) {
            throw new Deny('unknown_camera', 422);
        }

        if ($node && $camera->get('edgeNodeId') && $camera->get('edgeNodeId') !== $node->getId()) {
            throw new Deny('unknown_camera', 422);
        }

        return $camera;
    }

    /** @throws Deny */
    private function confidence(mixed $v): float
    {
        if (!is_numeric($v)) {
            throw new Deny('confidence_invalid', 422);
        }

        $f = (float) $v;

        // Some engines report 0-100.
        if ($f > 1 && $f <= 100) {
            $f /= 100;
        }

        if ($f < 0 || $f > 1) {
            throw new Deny('confidence_invalid', 422);
        }

        return round($f, 3);
    }

    /** @throws Deny */
    private function time(mixed $v): string
    {
        $now = time();

        if ($v === null || $v === '') {
            return gmdate('Y-m-d H:i:s', $now);
        }

        $t = is_string($v) ? strtotime($v) : false;

        if ($t === false) {
            throw new Deny('time_invalid', 422);
        }

        if ($t > $now + 300) {
            $t = $now;
        }

        if ($t < $now - $this->settings->maxEventAgeHours() * 3600) {
            throw new Deny('event_too_old', 422);
        }

        return gmdate('Y-m-d H:i:s', $t);
    }

    private function direction(string $v, string $cameraRole): string
    {
        $v = strtolower($v);

        if ($v === 'in') {
            return 'In';
        }

        if ($v === 'out') {
            return 'Out';
        }

        return match ($cameraRole) {
            'Entry' => 'In',
            'Exit' => 'Out',
            default => 'Unknown',
        };
    }

    private function source(string $v): string
    {
        foreach (['Hikvision', 'Dahua', 'Frigate', 'CodeProject', 'Simulator', 'Manual'] as $s) {
            if (strcasecmp($v, $s) === 0) {
                return $s;
            }
        }

        return 'Other';
    }

    /** A car that already has an order on the board: the picture goes on that order, nothing new is made. */
    private function attachToOrder(Entity $event, Entity $order, string $direction, ?string $jpeg, string $takenAt): void
    {
        $event->set([
            'status' => 'Matched',
            'washOrderId' => $order->getId(),
            'decidedAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($event, ['silent' => true]);

        if ($jpeg !== null) {
            $this->orderPhoto($order, $direction === 'Out' ? 'Exit' : 'Entry', $jpeg, $takenAt);
        }
    }

    /** The one place an order appears without a click - and only when the owner switched it on. */
    private function autoCreate(Entity $event, Entity $vehicle, ?string $jpeg, string $takenAt): void
    {
        $services = $this->orders->recentServices($vehicle->getId(), $vehicle->get('customerId'));

        if (!$services || !$vehicle->get('customerId')) {
            return; // nothing to base an order on, or no payer on file: a person chooses
        }

        $created = $this->orders->create((string) $event->get('plate'), [
            'customerId' => $vehicle->get('customerId'),
            'serviceId' => $services[0]['id'],
            'status' => 'New',
        ]);

        $event->set([
            'status' => 'Matched',
            'autoCreated' => true,
            'washOrderId' => $created->orderId,
            'decidedAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($event, ['silent' => true]);

        $order = $this->entityManager->getEntityById('WashOrder', $created->orderId);

        if ($order && $jpeg !== null) {
            $this->orderPhoto($order, 'Entry', $jpeg, $takenAt);
        }
    }

    /** Public: `confirm` uses it too. The camera picture becomes the order's Entry / Exit photo. */
    public function orderPhoto(Entity $order, string $kind, string $jpeg, string $takenAt): void
    {
        try {
            $this->photos->storeFromBase64($order, $kind, 'Camera', base64_encode($jpeg), null, $takenAt);
        } catch (\Throwable $e) {
            $this->log->warning('Anpr: could not attach the camera picture to the order: ' . $e->getMessage());
        }
    }
}
