<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Anpr\EdgeAuth;
use Espo\Custom\Tools\Anpr\Ingest;
use Espo\Custom\Tools\Anpr\Matcher;
use Espo\Custom\Tools\Anpr\Media;
use Espo\Custom\Tools\Anpr\MediaLog;
use Espo\Custom\Tools\Anpr\Orders;
use Espo\Custom\Tools\Anpr\Respond;
use Espo\Custom\Tools\Anpr\Retention;
use Espo\Custom\Tools\Anpr\Settings;
use Espo\Custom\Tools\Anpr\TestImage;
use Espo\Custom\Tools\Kiosk\Deny;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Kiosk\PhotoStore;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Plate recognition, the operator's and the administrator's side. POST /api/v1/Anpr/action/<name>
 * (the edge node's own actions are in `AnprEdge`).
 *
 *   status, pending            the board's "at the entrance" block
 *   confirm, dismiss           the operator decides (confirm = the EXISTING intake creates the order)
 *   adminOverview, adminIssueNode, adminRevokeNode, testEvent
 *   eraseSearch, erase         a data subject's request: find by plate, delete for good
 *   purge                      the retention sweep on demand (admin; dry-run supported)
 *
 * Rules: module `anpr` off -> 403 module_disabled (admin actions excepted, so it can be switched
 * back on); the role needs the PlateEvent scope (read to see, edit to decide); a device key
 * (tablet) is refused outright; an order is never made silently for a car that already has one.
 */
class Anpr
{
    use Respond;

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Acl $acl,
        private Config $config,
        private Log $log,
        private Modules $modules,
        private Settings $settings,
        private Matcher $matcher,
        private Orders $orders,
        private Ingest $ingest,
        private EdgeAuth $edgeAuth,
        private Media $media,
        private MediaLog $mediaLog,
        private Retention $retention,
        private PhotoStore $photos,
        DeviceGuard $deviceGuard
    ) {
        $deviceGuard->denyDevice($user);
    }

    // ------------------------------------------------------------------ operator

    /** Is the block to be drawn at all? Never an error: a role without access simply gets `enabled:false`. */
    public function postActionStatus(Request $request): stdClass
    {
        $enabled = $this->modules->moduleEnabled(Modules::ANPR) && $this->acl->check('PlateEvent', 'read');

        if (!$enabled) {
            return (object) ['enabled' => false];
        }

        return (object) [
            'enabled' => true,
            'canDecide' => $this->acl->check('PlateEvent', 'edit'),
            'pollSeconds' => $this->settings->pollSeconds(),
            'alertSound' => $this->settings->alertSound(),
            'autoCreate' => $this->settings->autoCreate(),
            'minConfidence' => $this->settings->minConfidence(),
            'highConfidence' => $this->settings->highConfidence(),
            'serverTime' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** The cars waiting at the entrance, newest first, each with what the operator needs to decide. */
    public function postActionPending(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $this->gate('read');
            $data = $request->getParsedBody() ?: new stdClass();
            $limit = min(30, max(1, (int) ($data->limit ?? 20)));

            $rows = $this->entityManager->getRDBRepository('PlateEvent')
                ->where(['status' => 'Pending', 'deleted' => false])
                ->order('takenAt', 'DESC')
                ->limit(0, $limit)
                ->find();

            $list = [];

            foreach ($rows as $e) {
                if ($this->acl->checkEntityRead($e)) {
                    $list[] = $this->card($e);
                }
            }

            $dayStart = gmdate('Y-m-d 00:00:00');

            return [
                'list' => $list,
                'total' => $this->entityManager->getRDBRepository('PlateEvent')->where(['status' => 'Pending', 'deleted' => false])->count(),
                'today' => [
                    'confirmed' => $this->countToday('Confirmed', $dayStart),
                    'dismissed' => $this->countToday('Dismissed', $dayStart),
                    'matched' => $this->countToday('Matched', $dayStart),
                ],
                'serverTime' => gmdate('Y-m-d H:i:s'),
                'pollSeconds' => $this->settings->pollSeconds(),
                'alertSound' => $this->settings->alertSound(),
                'minConfidence' => $this->settings->minConfidence(),
                'highConfidence' => $this->settings->highConfidence(),
            ];
        });
    }

    /**
     * The operator confirms (optionally correcting the plate): the EXISTING intake logic makes
     * customer + vehicle + order. A car that already has an order on the board answers 409
     * `active_order` - unless the operator chose `attach:true` (add the picture to that order).
     */
    public function postActionConfirm(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $this->gate('edit');
            $b = $request->getParsedBody() ?: new stdClass();
            $event = $this->event((string) ($b->eventId ?? ''));

            // a repeated tap answers with what happened the first time
            if (in_array($event->get('status'), ['Confirmed', 'Matched'], true) && $event->get('washOrderId')) {
                $order = $this->entityManager->getEntityById('WashOrder', (string) $event->get('washOrderId'));

                return ['ok' => true, 'replayed' => true, 'orderId' => $event->get('washOrderId'), 'orderName' => $order ? $order->get('name') : null];
            }

            if ($event->get('status') !== 'Pending') {
                throw new Deny('already_decided', 409, ['status' => (string) $event->get('status')]);
            }

            $plate = Matcher::normalize((string) ($b->plate ?? $event->get('plate')));

            if (strlen($plate) < 2 || strlen($plate) > 12) {
                throw new Deny('plate_invalid', 422);
            }

            $corrected = $plate !== $event->get('plate');
            $match = $this->matcher->match($plate);
            $vehicle = $match['kind'] === Matcher::EXACT ? $match['vehicle'] : null;
            $active = $this->matcher->activeOrder($vehicle);
            $jpeg = $this->media->contents($event->get('snapshotKey'));
            $takenAt = (string) $event->get('takenAt');

            if ($active) {
                if (empty($b->attach)) {
                    throw new Deny('active_order', 409, ['orderId' => $active->getId(), 'orderName' => (string) $active->get('name')]);
                }

                $this->decide($event, 'Confirmed', $active, $vehicle, $plate, $corrected, false);

                if ($jpeg !== null) {
                    $this->ingest->orderPhoto($active, $event->get('direction') === 'Out' ? 'Exit' : 'Entry', $jpeg, $takenAt);
                }

                return ['ok' => true, 'attached' => true, 'orderId' => $active->getId(), 'orderName' => (string) $active->get('name')];
            }

            $fields = (array) $b;
            $services = $vehicle ? $this->orders->recentServices($vehicle->getId(), $vehicle->get('customerId')) : [];

            if (empty($fields['serviceId'])) {
                if (!$services) {
                    throw new Deny('service_required', 400);
                }

                $fields['serviceId'] = $services[0]['id'];
            }

            try {
                $created = $this->orders->create($plate, $fields);
            } catch (Conflict $c) {
                // the "one car, one ramp" hook, in the same shape
                throw new Deny('active_order', 409, ['message' => $c->getMessage()]);
            } catch (\Espo\Core\Exceptions\BadRequest $c) {
                // a car with no customer on file needs at least the payer's name (docs/DATA_RULES.md)
                if (!str_contains($c->getMessage(), 'customer_name_required')) {
                    throw $c;
                }

                throw new Deny('customer_name_required', 400);
            }

            $order = $this->entityManager->getEntityById('WashOrder', (string) $created->orderId);

            if ($jpeg !== null && $order) {
                $this->ingest->orderPhoto($order, $event->get('direction') === 'Out' ? 'Exit' : 'Entry', $jpeg, $takenAt);
            }

            $vehicleEntity = $this->entityManager->getEntityById('Vehicle', (string) $created->vehicleId);
            $this->decide($event, 'Confirmed', $order, $vehicleEntity, $plate, $corrected, false);

            return [
                'ok' => true,
                'orderId' => $created->orderId,
                'orderName' => $created->orderName,
                'customerId' => $created->customerId,
                'vehicleId' => $created->vehicleId,
                'corrected' => $corrected,
            ];
        });
    }

    /** "Not a customer" (a passer-by, a false read): the card goes and so does its picture. */
    public function postActionDismiss(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $this->gate('edit');
            $event = $this->event((string) (($request->getParsedBody() ?: new stdClass())->eventId ?? ''));

            if ($event->get('status') === 'Dismissed') {
                return ['ok' => true, 'replayed' => true];
            }

            if ($event->get('status') !== 'Pending') {
                throw new Deny('already_decided', 409, ['status' => (string) $event->get('status')]);
            }

            $key = $event->get('snapshotKey');
            $event->set(['status' => 'Dismissed', 'decidedById' => $this->user->getId(), 'decidedAt' => gmdate('Y-m-d H:i:s'), 'snapshotKey' => null, 'hasSnapshot' => false]);
            $this->entityManager->saveEntity($event, ['silent' => true]);
            $this->media->destroy($key);

            return ['ok' => true];
        });
    }

    // ------------------------------------------------------------------ data subject

    public function postActionEraseSearch(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $this->requireEraser();
            $plate = (string) (($request->getParsedBody() ?: new stdClass())->plate ?? '');

            if (strlen(Matcher::normalize($plate)) < 2) {
                throw new Deny('plate_invalid', 422);
            }

            return ['ok' => true] + $this->retention->findPlate($plate);
        });
    }

    public function postActionErase(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $this->requireEraser();
            $b = $request->getParsedBody() ?: new stdClass();
            $plate = (string) ($b->plate ?? '');

            if (strlen(Matcher::normalize($plate)) < 2) {
                throw new Deny('plate_invalid', 422);
            }

            $done = $this->retention->erasePlate($plate, !empty($b->includeOrderPhotos));

            // The journal records THAT an erasure happened and how much - never the plate.
            $this->mediaLog->record('Erase', $this->user->getId(), [
                'always' => true,
                'detail' => sprintf('events=%d snapshots=%d photos=%d', $done['events'], $done['snapshots'], $done['cameraPhotos']),
                'ip' => $this->clientIp($request),
            ]);

            return ['ok' => true] + $done;
        });
    }

    // ------------------------------------------------------------------ administrator

    public function postActionAdminOverview(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () {
            $this->requireAdmin();
            $em = $this->entityManager;

            $nodes = [];

            foreach ($em->getRDBRepository('EdgeNode')->where(['deleted' => false])->order('createdAt', 'DESC')->find() as $n) {
                $seen = $n->get('lastSeenAt');
                $nodes[] = [
                    'id' => $n->getId(),
                    'name' => (string) $n->get('name'),
                    'status' => (string) $n->get('status'),
                    'online' => $n->get('status') === 'Active' && $seen && strtotime($seen . ' UTC') > time() - $this->settings->nodeOfflineSeconds(),
                    'lastSeenAt' => $seen,
                    'version' => $n->get('version'),
                    'queueDepth' => (int) $n->get('queueDepth'),
                    'lastIp' => $n->get('lastIp'),
                    'enrollExpiresAt' => $n->get('enrollExpiresAt'),
                    'clockSkewSeconds' => $n->get('clockSkewSeconds'),
                ];
            }

            $cameras = [];

            foreach ($em->getRDBRepository('Camera')->where(['deleted' => false])->order('name', 'ASC')->find() as $c) {
                $cameras[] = $this->cameraView($c);
            }

            $dayStart = gmdate('Y-m-d 00:00:00');

            return [
                'modules' => [
                    'anpr' => $this->modules->moduleEnabled(Modules::ANPR),
                    'cameras' => $this->modules->moduleEnabled(Modules::CAMERAS),
                ],
                'enabledModules' => $this->config->get('enabledModules'),
                'nodes' => $nodes,
                'cameras' => $cameras,
                'settings' => $this->settingsView(),
                'today' => [
                    'events' => $em->getRDBRepository('PlateEvent')->where(['deleted' => false, 'receivedAt>=' => $dayStart])->count(),
                    'pending' => $em->getRDBRepository('PlateEvent')->where(['deleted' => false, 'status' => 'Pending'])->count(),
                    'autoCreated' => $em->getRDBRepository('PlateEvent')->where(['deleted' => false, 'autoCreated' => true, 'receivedAt>=' => $dayStart])->count(),
                    'views' => $em->getRDBRepository('MediaAccess')->where(['deleted' => false, 'createdAt>=' => $dayStart])->count(),
                ],
                'serverTime' => gmdate('Y-m-d H:i:s'),
            ];
        });
    }

    /** "Issue a node": the one-time enrolment token is in THIS answer only. */
    public function postActionAdminIssueNode(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $this->requireAdmin();
            $name = trim((string) (($request->getParsedBody() ?: new stdClass())->name ?? ''));

            if ($name === '' || mb_strlen($name) > 60) {
                throw new Deny('name_required', 400);
            }

            $issued = $this->edgeAuth->issue($name);

            return [
                'ok' => true,
                'id' => $issued['node']->getId(),
                'name' => $name,
                'enrollToken' => $issued['enrollToken'],
                'expiresAt' => $issued['node']->get('enrollExpiresAt'),
                'registerPath' => '/api/v1/Anpr/action/register',
            ];
        });
    }

    public function postActionAdminRevokeNode(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $this->requireAdmin();
            $node = $this->entityManager->getEntityById('EdgeNode', (string) (($request->getParsedBody() ?: new stdClass())->nodeId ?? ''));

            if (!$node) {
                throw new Deny('not_found', 404);
            }

            $this->edgeAuth->revoke($node);

            return ['ok' => true];
        });
    }

    /**
     * A test event as if a camera had seen a car (a synthetic picture with the plate on it).
     * Goes through the same pipeline as a real one - policy, dedup, everything.
     */
    public function postActionTestEvent(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $this->requireAdmin();

            if (!$this->modules->moduleEnabled(Modules::ANPR)) {
                throw new Deny('module_disabled', 403);
            }

            $b = $request->getParsedBody() ?: new stdClass();
            $plate = Matcher::normalize((string) ($b->plate ?? 'TEST123'));
            $camera = !empty($b->camera)
                ? $this->entityManager->getRDBRepository('Camera')->where(['code' => strtolower((string) $b->camera), 'deleted' => false])->findOne()
                : $this->entityManager->getRDBRepository('Camera')->where(['isActive' => true, 'deleted' => false])->order('role', 'ASC')->findOne();

            if (!$camera) {
                throw new Deny('no_camera', 422);
            }

            $node = $camera->get('edgeNodeId') ? $this->entityManager->getEntityById('EdgeNode', (string) $camera->get('edgeNodeId')) : null;

            $event = (object) [
                'eventId' => 'test-' . bin2hex(random_bytes(6)),
                'camera' => $camera->get('code'),
                'plateRaw' => $plate,
                'confidence' => isset($b->confidence) ? (float) $b->confidence : 0.95,
                'takenAt' => gmdate('c'),
                'source' => 'Simulator',
                'snapshotBase64' => base64_encode(TestImage::jpeg($plate)),
            ];

            return $this->ingest->handle($node, $event, $this->clientIp($request));
        });
    }

    public function postActionPurge(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $this->requireAdmin();
            $dry = !empty(($request->getParsedBody() ?: new stdClass())->dryRun);

            return ['ok' => true, 'dryRun' => $dry] + $this->retention->purge($dry);
        });
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param callable(): array<string, mixed> $fn
     */
    private function guard(Response $response, callable $fn, bool $moduleGate = true): ?stdClass
    {
        try {
            $out = $fn();
            $response->setHeader('Cache-Control', 'no-store');

            return (object) $out;
        } catch (Deny $d) {
            return $this->refuse($response, $d->status(), $d->errorCode(), $d->params());
        } catch (\Espo\Core\Exceptions\Error $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->log->error('Anpr: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->refuse($response, 500, 'server_error');
        }
    }

    /** @throws Deny */
    private function gate(string $action): void
    {
        if (!$this->modules->moduleEnabled(Modules::ANPR)) {
            throw new Deny('module_disabled', 403);
        }

        if (!$this->acl->check('PlateEvent', $action)) {
            throw new Deny('forbidden', 403);
        }
    }

    /** @throws Deny */
    private function requireAdmin(): void
    {
        if (!$this->user->isAdmin() || $this->user->isApi()) {
            throw new Deny('forbidden', 403);
        }
    }

    /** A data subject's request: an administrator, or a role that may delete plate events. */
    private function requireEraser(): void
    {
        if ($this->user->isAdmin() && !$this->user->isApi()) {
            return;
        }

        $this->gate('delete');
    }

    /** @throws Deny */
    private function event(string $id): Entity
    {
        $event = $id !== '' ? $this->entityManager->getEntityById('PlateEvent', $id) : null;

        if (!$event || !$this->acl->checkEntityRead($event)) {
            throw new Deny('not_found', 404);
        }

        return $event;
    }

    private function decide(Entity $event, string $status, ?Entity $order, ?Entity $vehicle, string $plate, bool $corrected, bool $auto): void
    {
        $event->set([
            'status' => $status,
            'plate' => $plate,
            'name' => $plate,
            'corrected' => $corrected,
            'autoCreated' => $auto,
            'washOrderId' => $order ? $order->getId() : null,
            'vehicleId' => $vehicle ? $vehicle->getId() : null,
            'customerId' => $vehicle ? $vehicle->get('customerId') : null,
            'decidedById' => $this->user->getId(),
            'decidedAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($event, ['silent' => true]);
    }

    private function countToday(string $status, string $dayStart): int
    {
        return $this->entityManager->getRDBRepository('PlateEvent')
            ->where(['deleted' => false, 'status' => $status, 'decidedAt>=' => $dayStart])
            ->count();
    }

    /** @return array<string, mixed> */
    private function card(Entity $e): array
    {
        $vehicle = $e->get('vehicleId') ? $this->entityManager->getEntityById('Vehicle', (string) $e->get('vehicleId')) : null;
        $suggestedVehicle = null;

        if (!$vehicle && $e->get('plateSuggested')) {
            $suggestedVehicle = $this->entityManager->getRDBRepository('Vehicle')->where(['name' => $e->get('plateSuggested'), 'deleted' => false])->findOne();
        }

        $known = $vehicle ?: $suggestedVehicle;
        $customer = $known && $known->get('customerId') ? $this->entityManager->getEntityById('Customer', (string) $known->get('customerId')) : null;
        $camera = $e->get('cameraId') ? $this->entityManager->getEntityById('Camera', (string) $e->get('cameraId')) : null;
        $confidence = (float) $e->get('confidence');
        $band = $confidence < $this->settings->minConfidence() ? 'low' : ($confidence >= $this->settings->highConfidence() ? 'high' : 'mid');

        return [
            'id' => $e->getId(),
            'plate' => (string) $e->get('plate'),
            'plateRaw' => (string) $e->get('plateRaw'),
            'suggestedPlate' => $e->get('plateSuggested'),
            'matchKind' => (string) $e->get('matchKind'),
            'confidence' => $confidence,
            'band' => $band,
            'direction' => (string) $e->get('direction'),
            'takenAt' => $e->get('takenAt'),
            'seenCount' => (int) $e->get('seenCount'),
            'camera' => $camera ? ['id' => $camera->getId(), 'name' => (string) $camera->get('name'), 'role' => (string) $camera->get('role')] : null,
            'snapshotUrl' => $e->get('hasSnapshot') ? '?entryPoint=cwMedia&type=plateEvent&id=' . $e->getId() : null,
            'known' => $known !== null,
            'vehicleId' => $known ? $known->getId() : null,
            'customer' => $customer ? ['id' => $customer->getId(), 'name' => (string) $customer->get('name'), 'isCorporate' => (bool) $customer->get('isCorporate')] : null,
            'services' => $known ? $this->orders->recentServices($known->getId(), $known->get('customerId')) : [],
            'activeOrder' => ($ao = $this->matcher->activeOrder($known)) ? ['id' => $ao->getId(), 'name' => (string) $ao->get('name'), 'status' => (string) $ao->get('status')] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function cameraView(Entity $c): array
    {
        $node = $c->get('edgeNodeId') ? $this->entityManager->getEntityById('EdgeNode', (string) $c->get('edgeNodeId')) : null;
        $seen = $node ? $node->get('lastSeenAt') : null;
        $nodeOnline = $node && $node->get('status') === 'Active' && $seen && strtotime($seen . ' UTC') > time() - $this->settings->nodeOfflineSeconds();

        return [
            'id' => $c->getId(),
            'name' => (string) $c->get('name'),
            'code' => (string) $c->get('code'),
            'role' => (string) $c->get('role'),
            'isActive' => (bool) $c->get('isActive'),
            'edgeNodeId' => $c->get('edgeNodeId'),
            'edgeNodeName' => $node ? (string) $node->get('name') : null,
            'nodeOnline' => (bool) $nodeOnline,
            'online' => (bool) $c->get('online') && $nodeOnline,
            'lastFrameAt' => $c->get('lastFrameAt'),
            'lastError' => $c->get('lastError'),
            'secretSet' => (bool) $c->get('secretSet'),
        ];
    }

    /** @return array<string, mixed> */
    private function settingsView(): array
    {
        return [
            'anprAutoCreate' => $this->settings->autoCreate(),
            'anprMinConfidence' => $this->settings->minConfidence(),
            'anprHighConfidence' => $this->settings->highConfidence(),
            'anprDedupSeconds' => $this->settings->dedupSeconds(),
            'anprRetentionDays' => $this->settings->snapshotDays(),
            'anprEventRetentionDays' => $this->settings->eventDays(),
            'anprAccessLogDays' => $this->settings->accessLogDays(),
            'anprRateLimitPerMinute' => $this->settings->rateLimitPerMinute(),
            'anprPollSeconds' => $this->settings->pollSeconds(),
            'anprAlertSound' => $this->settings->alertSound(),
            'anprMaxEventAgeHours' => $this->settings->maxEventAgeHours(),
            'camerasFrameKeepHours' => $this->settings->frameKeepHours(),
            'camerasViewBase' => $this->settings->viewBase(),
        ];
    }
}
