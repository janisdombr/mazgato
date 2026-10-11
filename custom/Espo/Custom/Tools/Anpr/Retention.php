<?php

namespace Espo\Custom\Tools\Anpr;

use Espo\ORM\EntityManager;

/**
 * The privacy clean-up of the plate / camera modules - all of it settings-driven, none of it
 * a soft delete (a "deleted" row would keep the plate it was meant to forget):
 *
 *   snapshots   removed `anprRetentionDays` after the event (default 30) - the file AND its row;
 *   events      removed `anprEventRetentionDays` after the event (default 90);
 *   frames      on-demand camera frames and clip lists removed after `camerasFrameKeepHours`;
 *   access log  "who looked" rows removed after `anprAccessLogDays` (default 180);
 *   erasure     `erasePlate()` = a data subject's request: every event, snapshot and (optionally)
 *               camera photo of one plate, found by number and deleted for good.
 *
 * Run by `bin/command anpr-purge [--dry-run]` and the daily job `PurgeAnprData`.
 * The default periods are a starting point from the privacy guide in docs/ANPR.md and MUST be
 * confirmed with a lawyer for the actual site.
 */
class Retention
{
    public function __construct(
        private EntityManager $entityManager,
        private Settings $settings,
        private Media $media
    ) {}

    /**
     * @return array{snapshots: int, events: int, frames: int, accessLog: int, commandsExpired: int}
     */
    public function purge(bool $dryRun = false): array
    {
        $today = gmdate('Y-m-d');
        $em = $this->entityManager;
        $out = ['snapshots' => 0, 'events' => 0, 'frames' => 0, 'accessLog' => 0, 'commandsExpired' => 0];

        // 1. snapshots past their date (the event row stays until its own date)
        $snaps = $em->getRDBRepository('PlateEvent')
            ->where(['deleted' => false, 'snapshotKey!=' => null, 'snapshotRetainUntil<' => $today])
            ->find();

        foreach ($snaps as $e) {
            $out['snapshots']++;

            if (!$dryRun) {
                $this->media->destroy($e->get('snapshotKey'));
                $e->set(['snapshotKey' => null, 'hasSnapshot' => false]);
                $em->saveEntity($e, ['silent' => true]);
            }
        }

        // 2. events past their date
        $events = $em->getRDBRepository('PlateEvent')
            ->where(['deleted' => false, 'retainUntil<' => $today])
            ->find();

        foreach ($events as $e) {
            $out['events']++;

            if (!$dryRun) {
                $this->hardDeleteEvent($e->getId(), $e->get('snapshotKey'));
            }
        }

        // 3. frames / clip lists, and queued commands nobody picked up
        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->settings->frameKeepHours() * 3600);
        $frames = $em->getRDBRepository('CameraCommand')->where(['deleted' => false, 'requestedAt<' => $cutoff])->find();

        foreach ($frames as $c) {
            $out['frames']++;

            if (!$dryRun) {
                $this->media->destroy($c->get('fileKey'));
                $em->getRDBRepository('CameraCommand')->deleteFromDb($c->getId());
            }
        }

        $stale = gmdate('Y-m-d H:i:s', time() - 300);
        $open = $em->getRDBRepository('CameraCommand')->where(['deleted' => false, 'status' => ['Queued', 'Leased'], 'requestedAt<' => $stale])->find();

        foreach ($open as $c) {
            $out['commandsExpired']++;

            if (!$dryRun) {
                $c->set(['status' => 'Expired']);
                $em->saveEntity($c, ['silent' => true]);
            }
        }

        // 4. the journal itself
        $log = $em->getRDBRepository('MediaAccess')
            ->where(['deleted' => false, 'createdAt<' => gmdate('Y-m-d H:i:s', time() - $this->settings->accessLogDays() * 86400)])
            ->find();

        foreach ($log as $row) {
            $out['accessLog']++;

            if (!$dryRun) {
                $em->getRDBRepository('MediaAccess')->deleteFromDb($row->getId());
            }
        }

        return $out;
    }

    /**
     * What an erasure request for one plate would remove.
     *
     * @return array{plate: string, events: int, snapshots: int, cameraPhotos: int, items: array<int, array<string, mixed>>}
     */
    public function findPlate(string $plate): array
    {
        $plate = Matcher::normalize($plate);
        $em = $this->entityManager;

        $events = $em->getRDBRepository('PlateEvent')
            ->where(['deleted' => false, 'OR' => [['plate' => $plate], ['plateSuggested' => $plate]]])
            ->order('takenAt', 'DESC')
            ->find();

        $items = [];
        $snapshots = 0;

        foreach ($events as $e) {
            $snapshots += $e->get('snapshotKey') ? 1 : 0;
            $items[] = ['id' => $e->getId(), 'takenAt' => $e->get('takenAt'), 'status' => $e->get('status'), 'hasSnapshot' => (bool) $e->get('snapshotKey')];
        }

        return [
            'plate' => $plate,
            'events' => count($items),
            'snapshots' => $snapshots,
            'cameraPhotos' => count($this->cameraPhotos($plate)),
            'items' => array_slice($items, 0, 50),
        ];
    }

    /**
     * Delete everything about one plate: events (with their pictures) and, when asked, the
     * camera photos on the orders of that vehicle. The vehicle / customer / order records are
     * business records and are NOT touched here.
     *
     * @return array{events: int, snapshots: int, cameraPhotos: int}
     */
    public function erasePlate(string $plate, bool $includeOrderPhotos): array
    {
        $plate = Matcher::normalize($plate);
        $em = $this->entityManager;
        $events = 0;
        $snaps = 0;

        $rows = $em->getRDBRepository('PlateEvent')
            ->where(['deleted' => false, 'OR' => [['plate' => $plate], ['plateSuggested' => $plate]]])
            ->find();

        foreach ($rows as $e) {
            $snaps += $e->get('snapshotKey') ? 1 : 0;
            $this->hardDeleteEvent($e->getId(), $e->get('snapshotKey'));
            $events++;
        }

        $photos = 0;

        if ($includeOrderPhotos) {
            foreach ($this->cameraPhotos($plate) as $p) {
                $em->removeEntity($p); // OrderPhoto hook removes the file
                $em->getRDBRepository('OrderPhoto')->deleteFromDb($p->getId());
                $photos++;
            }
        }

        return ['events' => $events, 'snapshots' => $snaps, 'cameraPhotos' => $photos];
    }

    /** @return array<int, \Espo\ORM\Entity> */
    private function cameraPhotos(string $plate): array
    {
        $vehicle = $this->entityManager->getRDBRepository('Vehicle')->where(['name' => $plate, 'deleted' => false])->findOne();

        if (!$vehicle) {
            return [];
        }

        $orders = $this->entityManager->getRDBRepository('WashOrder')->select(['id'])->where(['vehicleId' => $vehicle->getId(), 'deleted' => false])->find();
        $ids = [];

        foreach ($orders as $o) {
            $ids[] = $o->getId();
        }

        if (!$ids) {
            return [];
        }

        $out = [];
        $photos = $this->entityManager->getRDBRepository('OrderPhoto')
            ->where(['washOrderId' => $ids, 'source' => 'Camera', 'deleted' => false])
            ->find();

        foreach ($photos as $p) {
            $out[] = $p;
        }

        return $out;
    }

    private function hardDeleteEvent(string $id, ?string $snapshotKey): void
    {
        $this->media->destroy($snapshotKey);
        $this->entityManager->getRDBRepository('PlateEvent')->deleteFromDb($id);
    }
}
