<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Kiosk\Audit;
use Espo\Custom\Tools\Kiosk\Deny;
use Espo\Custom\Tools\Kiosk\DeviceAdmin;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Kiosk\PhotoStore;
use Espo\Custom\Tools\Kiosk\Pin;
use Espo\Custom\Tools\Kiosk\Queue;
use Espo\Custom\Tools\Kiosk\SessionManager;
use Espo\Custom\Tools\Kiosk\Workflow;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Custom\Tools\Shifts\ClockService;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * The worker tablet's API.  POST /api/v1/Kiosk/action/<name>
 *
 * Two kinds of caller:
 *
 *  1. THE DEVICE — an API user with the Kiosk role, authenticated by `X-Api-Key`. Every
 *     device action first asks the licence/setting gate (`moduleEnabled('kiosk')`, else
 *     403), then checks that the caller really is a Kiosk device. A worker's actions also
 *     need `X-Kiosk-Session` (from `pinLogin`). Mutating actions carry a client-generated
 *     `clientActionId`: the first time it is executed and written to the audit ledger,
 *     a repeat (offline queue retry, double tap) is answered from the ledger and does
 *     nothing again. Refusals answer `{ok:false, error:<code>, …}` with the HTTP status.
 *  2. AN ADMIN, signed in to the CRM — `adminOverview`, `setPin`, `unlockPin`,
 *     `issueDevice`, `revokeDevice`, `qr`. Not gated by the module flag, so the
 *     administration page keeps working to switch it back on.
 *
 * The rules (own works only, one car one ramp, write-once stamps) are in
 * `Tools/Kiosk/Workflow`; the audit/idempotency ledger in `Tools/Kiosk/Audit`.
 */
class Kiosk
{
    /** pinLogin attempts one device may make per minute, across all workers. */
    private const PIN_ATTEMPTS_PER_MINUTE = 30;

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Config $config,
        private Log $log,
        private Modules $modules,
        private DeviceGuard $deviceGuard,
        private DeviceAdmin $deviceAdmin,
        private SessionManager $sessions,
        private Pin $pin,
        private Audit $audit,
        private Queue $queue,
        private Workflow $workflow,
        private PhotoStore $photoStore,
        private ClockService $clock
    ) {}

    // =========================================================================================
    //  Device actions
    // =========================================================================================

    /** Handshake: module on, who the device is, the settings the tablet needs. */
    public function postActionStatus(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'status', [], function () {
            return ['result' => [
                'enabled' => true,
                'serverTime' => gmdate('Y-m-d H:i:s'),
                'device' => ['id' => $this->user->getId(), 'name' => (string) ($this->user->get('lastName') ?: $this->user->get('userName'))],
                'config' => $this->clientConfig(),
            ]];
        });
    }

    /** The tiles of the "who are you" screen: Hired staff only, nothing private. */
    public function postActionRoster(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'roster', [], function () {
            $rows = $this->entityManager->getRDBRepository('Employee')
                ->where(['status' => 'Hired', 'deleted' => false])
                ->order('name', 'ASC')
                ->find();

            $staff = [];

            foreach ($rows as $e) {
                $staff[] = [
                    'id' => $e->getId(),
                    'name' => (string) $e->get('name'),
                    'onShift' => (bool) $e->get('onShift'),
                    'language' => (string) ($e->get('language') ?: 'lv_LV'),
                    'hasPin' => (bool) $e->get('pinHash'),
                ];
            }

            return ['result' => ['staff' => $staff, 'serverTime' => gmdate('Y-m-d H:i:s')]];
        });
    }

    /** PIN sign-in: pick the tile, type the PIN. Wrong = 403, locked = 423, throttled = 429. */
    public function postActionPinLogin(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'pinLogin', ['audit' => true, 'noResult' => true], function ($ctx, $body) use ($request) {
            if ($this->audit->countRecentOf($this->user->getId(), 'pinLogin', 60) >= self::PIN_ATTEMPTS_PER_MINUTE) {
                throw new Deny('too_many_attempts', 429, ['retryAfter' => 60]);
            }

            $employeeId = (string) ($body->employeeId ?? '');
            $pin = (string) ($body->pin ?? '');
            $employee = $employeeId !== '' ? $this->entityManager->getEntityById('Employee', $employeeId) : null;

            if (!$employee || $employee->get('status') !== 'Hired') {
                throw new Deny('employee_unavailable', 403);
            }

            $ctx->employeeId = $employee->getId();

            // Not even looked at when it is malformed — and never written anywhere.
            $check = preg_match('/^\d{4,6}$/', $pin)
                ? $this->pin->check($employee, $pin)
                : ['result' => $employee->get('pinHash') ? Pin::WRONG : Pin::NOT_SET];

            switch ($check['result']) {
                case Pin::NOT_SET:
                    throw new Deny('pin_not_set', 403);
                case Pin::LOCKED:
                    $ctx->meta = ['lockedUntil' => $check['lockedUntil'] ?? null];

                    throw new Deny('pin_locked', 423, ['lockedUntil' => $check['lockedUntil'] ?? null]);
                case Pin::WRONG:
                    $ctx->meta = ['attemptsLeft' => $check['attemptsLeft'] ?? null];

                    throw new Deny('pin_wrong', 403, ['attemptsLeft' => $check['attemptsLeft'] ?? null]);
            }

            $started = $this->sessions->start($employee, $this->user, $this->ip($request));
            $ctx->meta = ['sessionId' => $started['session']->getId()];

            return ['result' => [
                'token' => $started['token'],
                'expiresAt' => $started['session']->get('expiresAt'),
                'employee' => $this->employeeView($employee),
                'config' => $this->clientConfig(),
            ]];
        });
    }

    public function postActionLogout(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'logout', ['audit' => true, 'mutate' => false], function ($ctx) use ($request) {
            $session = $this->sessions->endByToken((string) $request->getHeader('X-Kiosk-Session'), $this->user, 'Logout');

            if ($session) {
                $ctx->employeeId = $session->get('employeeId');
            }

            return ['result' => ['ok' => true]];
        });
    }

    public function postActionMe(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'me', ['session' => true], function ($ctx, $body, Entity $employee) {
            $employee = $this->entityManager->getEntityById('Employee', $employee->getId());

            return ['result' => [
                'employee' => $this->employeeView($employee),
                'today' => $this->queue->todaySummary($employee),
                'serverTime' => gmdate('Y-m-d H:i:s'),
            ]];
        });
    }

    /** The queue: my cars, cars on the ramp, waiting, today's reservations. */
    public function postActionQueue(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'queue', ['session' => true], function ($ctx, $body, Entity $employee) {
            return ['result' => $this->queue->build($employee)];
        });
    }

    public function postActionOrder(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'order', ['session' => true], function ($ctx, $body, Entity $employee) {
            $order = $this->entityManager->getEntityById('WashOrder', (string) ($body->orderId ?? ''));

            if (!$order) {
                throw new Deny('not_found', 404);
            }

            return ['result' => ['order' => $this->queue->card($order, $employee, true), 'serverTime' => gmdate('Y-m-d H:i:s')]];
        });
    }

    public function postActionTakeOrder(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'takeOrder', ['session' => true, 'mutate' => true], function ($ctx, $body, Entity $employee) {
            $ctx->washOrderId = (string) ($body->orderId ?? '');
            $r = $this->workflow->takeOrder($employee, $ctx->washOrderId);
            $ctx->meta = ['taken' => $r['taken']];

            return ['result' => $r];
        });
    }

    public function postActionTakeWork(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'takeWork', ['session' => true, 'mutate' => true], function ($ctx, $body, Entity $employee) {
            $ctx->orderWorkId = (string) ($body->orderWorkId ?? '');
            $this->refsOfLine($ctx);

            return ['result' => $this->workflow->takeWork($employee, $ctx->orderWorkId)];
        });
    }

    public function postActionStartOrder(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'startOrder', ['session' => true, 'mutate' => true], function ($ctx, $body, Entity $employee) {
            $ctx->washOrderId = (string) ($body->orderId ?? '');

            return ['result' => $this->workflow->startOrder($employee, $ctx->washOrderId)];
        });
    }

    public function postActionFinishOrder(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'finishOrder', ['session' => true, 'mutate' => true], function ($ctx, $body, Entity $employee) {
            $ctx->washOrderId = (string) ($body->orderId ?? '');
            $r = $this->workflow->finishOrder($employee, $ctx->washOrderId, !empty($body->force));
            $ctx->meta = $r['meta'];

            return ['result' => $r['result']];
        });
    }

    public function postActionStartWork(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'startWork', ['session' => true, 'mutate' => true], function ($ctx, $body, Entity $employee) {
            $ctx->orderWorkId = (string) ($body->orderWorkId ?? '');
            $this->refsOfLine($ctx);

            return ['result' => $this->workflow->startWork($employee, $ctx->orderWorkId)];
        });
    }

    /** Mark my work done. Someone else's line: 403 `foreign_work`, audited. */
    public function postActionWorkDone(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'workDone', ['session' => true, 'mutate' => true], function ($ctx, $body, Entity $employee) {
            $ctx->orderWorkId = (string) ($body->orderWorkId ?? '');
            $this->refsOfLine($ctx);

            return ['result' => $this->workflow->workDone($employee, $this->user, $ctx->orderWorkId)];
        });
    }

    public function postActionWorkUndone(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'workUndone', ['session' => true, 'mutate' => true], function ($ctx, $body, Entity $employee) {
            $ctx->orderWorkId = (string) ($body->orderWorkId ?? '');
            $this->refsOfLine($ctx);

            return ['result' => $this->workflow->workUndone($employee, $ctx->orderWorkId)];
        });
    }

    /** Shift in/out through the shared ClockService (explicit mode, so a replay never flips it). */
    public function postActionClock(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'clock', ['session' => true, 'mutate' => true], function ($ctx, $body, Entity $employee) {
            $mode = (string) ($body->mode ?? '');

            if (!in_array($mode, ['in', 'out'], true)) {
                throw new Deny('bad_request', 400, ['field' => 'mode']);
            }

            $r = $mode === 'in' ? $this->clock->clockIn($employee->getId()) : $this->clock->clockOut($employee->getId());
            $ctx->meta = ['mode' => $mode, 'changed' => $r['changed']];

            if ($mode === 'out' && !empty($r['startedAt'])) {
                $r['summary'] = $this->queue->todaySummary($employee, $r['startedAt'], gmdate('Y-m-d H:i:s', time() + 1));
            }

            return ['result' => $r];
        });
    }

    /** Before/after photo from the tablet camera (base64 JPEG/PNG/WebP, re-encoded server-side). */
    public function postActionUploadPhoto(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, 'uploadPhoto', ['session' => true, 'mutate' => true], function ($ctx, $body, Entity $employee) {
            $ctx->washOrderId = (string) ($body->orderId ?? '');
            $kind = (string) ($body->kind ?? '');

            if (!in_array($kind, PhotoStore::KINDS_KIOSK, true)) {
                throw new Deny('bad_request', 400, ['field' => 'kind']);
            }

            $order = $this->workflow->orderForPhoto($employee, $ctx->washOrderId);
            $photo = $this->photoStore->storeFromBase64(
                $order,
                $kind,
                'Kiosk',
                (string) ($body->image ?? ''),
                $employee,
                isset($body->takenAt) ? (string) $body->takenAt : null
            );

            $ctx->meta = ['photoId' => $photo->getId(), 'kind' => $kind];

            return ['result' => [
                'photoId' => $photo->getId(),
                'kind' => $kind,
                'order' => $this->queue->card($order, $employee, true),
            ]];
        });
    }

    // =========================================================================================
    //  Admin actions (a CRM administrator, not a device)
    // =========================================================================================

    public function postActionAdminOverview(Request $request): stdClass
    {
        $this->requireAdmin();

        $staff = [];
        $rows = $this->entityManager->getRDBRepository('Employee')
            ->where(['deleted' => false, 'status' => ['Hired', 'OnLeave']])
            ->order('name', 'ASC')
            ->find();

        foreach ($rows as $e) {
            $staff[] = [
                'id' => $e->getId(),
                'name' => (string) $e->get('name'),
                'status' => (string) $e->get('status'),
                'language' => (string) ($e->get('language') ?: 'lv_LV'),
                'pin' => [
                    'status' => $this->pin->status($e),
                    'setAt' => $e->get('pinSetAt'),
                    'lockedUntil' => $e->get('pinLockedUntil'),
                ],
            ];
        }

        [$dayStart, $dayEnd] = $this->queue->todayBoundsUtc();

        return (object) [
            'moduleEnabled' => $this->modules->moduleEnabled(Modules::KIOSK),
            'kioskPath' => '/kiosk/',
            'devices' => $this->deviceAdmin->devices(),
            'staff' => $staff,
            'settings' => $this->settingsView(),
            'today' => [
                'events' => $this->entityManager->getRDBRepository('KioskEvent')->where(['createdAt>=' => $dayStart, 'createdAt<' => $dayEnd])->count(),
                'refused' => $this->entityManager->getRDBRepository('KioskEvent')->where(['createdAt>=' => $dayStart, 'createdAt<' => $dayEnd, 'outcome!=' => 'Ok'])->count(),
            ],
        ];
    }

    /** Set or replace a worker's PIN. The PIN is hashed here and never returned or logged. */
    public function postActionSetPin(Request $request): stdClass
    {
        $this->requireAdmin();

        $data = $request->getParsedBody();
        $employee = $this->entityManager->getEntityById('Employee', (string) ($data->employeeId ?? ''));

        if (!$employee) {
            throw new NotFound('Employee not found.');
        }

        $pin = (string) ($data->pin ?? '');

        if (!$this->pin->isAcceptable($pin)) {
            throw new BadRequest('PIN must be 4-6 digits, not all the same and not a straight run.');
        }

        $this->pin->set($employee, $pin);

        return (object) ['ok' => true, 'pinSetAt' => $employee->get('pinSetAt')];
    }

    public function postActionUnlockPin(Request $request): stdClass
    {
        $this->requireAdmin();

        $employee = $this->entityManager->getEntityById('Employee', (string) ($request->getParsedBody()->employeeId ?? ''));

        if (!$employee) {
            throw new NotFound('Employee not found.');
        }

        $this->pin->unlock($employee);

        return (object) ['ok' => true];
    }

    /** "Issue a device": creates the API user + Kiosk role. The key is in THIS reply only. */
    public function postActionIssueDevice(Request $request): stdClass
    {
        $this->requireAdmin();

        // The tablet's address is the origin the admin is looking at (the client composes
        // it; `siteUrl` in the config is whatever the install was configured with).
        $issued = $this->deviceAdmin->issue((string) ($request->getParsedBody()->name ?? ''));

        return (object) ($issued + ['kioskPath' => '/kiosk/', 'provisionFragment' => '#key=' . $issued['apiKey']]);
    }

    public function postActionRevokeDevice(Request $request): stdClass
    {
        $this->requireAdmin();

        $ended = $this->deviceAdmin->revoke((string) ($request->getParsedBody()->deviceId ?? ''));

        return (object) ['ok' => true, 'sessionsEnded' => $ended];
    }

    /** QR code (SVG) of a short text — the kiosk link or a device's setup link. */
    public function postActionQr(Request $request): stdClass
    {
        $this->requireAdmin();

        $text = (string) ($request->getParsedBody()->text ?? '');

        if ($text === '' || strlen($text) > 400) {
            throw new BadRequest("Field 'text' is required (up to 400 characters).");
        }

        $options = new \chillerlan\QRCode\QROptions();
        $options->outputType = \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG;
        $options->outputBase64 = false;
        $options->addQuietzone = true;
        $options->quietzoneSize = 2;
        $svg = (new \chillerlan\QRCode\QRCode($options))->render($text);

        // A QR needs dark modules on a LIGHT ground whatever the theme: bake a white plate in.
        $svg = preg_replace('/(<svg[^>]*>)/', '$1<rect width="100%" height="100%" fill="#fff"/>', $svg, 1);

        return (object) ['svg' => $svg];
    }

    // =========================================================================================
    //  Plumbing
    // =========================================================================================

    /**
     * One pipeline for every device action: gate → device → throttle → session →
     * idempotency → execute → audit → reply.
     *
     * @param array{session?: bool, mutate?: bool, audit?: bool, noResult?: bool} $opts
     * @param callable(stdClass, stdClass, ?Entity): array{result?: array<string, mixed>} $fn
     */
    private function run(Request $request, Response $response, string $action, array $opts, callable $fn): ?stdClass
    {
        $ctx = new stdClass();
        $mutate = $opts['mutate'] ?? false;
        $audited = $mutate || !empty($opts['audit']);
        $body = $request->getParsedBody() ?: new stdClass();
        $clientActionId = null;

        try {
            if (!$this->modules->moduleEnabled(Modules::KIOSK)) {
                throw new Deny('module_disabled', 403);
            }

            if (!$this->deviceGuard->isDevice($this->user)) {
                throw new Deny('not_a_device', 403);
            }

            if ($mutate) {
                $clientActionId = (string) ($body->clientActionId ?? '');

                if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientActionId)) {
                    throw new Deny('client_action_id_required', 400);
                }

                $known = $this->audit->find($clientActionId);

                if ($known) {
                    if ($known->get('deviceId') !== $this->user->getId()) {
                        throw new Deny('duplicate_id', 409);
                    }

                    $this->audit->noteReplay($known);

                    return $this->replay($response, $known);
                }

                $this->throttle();
            }

            $employee = null;

            if (!empty($opts['session'])) {
                $session = $this->sessions->resolve((string) $request->getHeader('X-Kiosk-Session'), $this->user);

                if (!$session) {
                    throw new Deny('session_expired', 401);
                }

                $employee = $this->entityManager->getEntityById('Employee', (string) $session->get('employeeId'));
                $ctx->employeeId = $session->get('employeeId');
            }

            $out = $fn($ctx, $body, $employee);
            $result = $out['result'] ?? [];

            if ($audited) {
                $this->record($request, $action, 'Ok', 200, $ctx, $clientActionId, $body, $opts['noResult'] ?? false ? null : $result, null);
            }

            return (object) $result;
        } catch (Deny $d) {
            // Failures of the gate itself are not the worker's actions — nothing to audit.
            $gate = in_array($d->errorCode(), ['module_disabled', 'not_a_device', 'client_action_id_required', 'duplicate_id'], true);

            if (($audited && !$gate) && !($d->status() === 429 && $d->errorCode() === 'rate_limited' && !$this->shouldLogRateLimit())) {
                $this->record(
                    $request,
                    $action,
                    $d->outcome(),
                    $d->status(),
                    $ctx,
                    $clientActionId,
                    $body,
                    ['ok' => false, 'error' => $d->errorCode()] + $d->params(),
                    $d->errorCode()
                );
            }

            return $this->refuse($response, $d->status(), $d->errorCode(), $d->params());
        } catch (\Throwable $e) {
            $this->log->error('Kiosk/' . $action . ': ' . get_class($e) . ': ' . $e->getMessage());

            return $this->refuse($response, 500, 'server_error', []);
        }
    }

    /**
     * Click-spam guard: more than `kioskRateLimitPerMinute` ledger rows from this device in
     * the last minute and the next mutation gets 429 + Retry-After. The tablet's offline
     * queue treats 429 as "wait and retry", never as "drop".
     *
     * @throws Deny
     */
    private function throttle(): void
    {
        $limit = max(10, (int) ($this->config->get('kioskRateLimitPerMinute') ?: 90));

        if ($this->audit->countRecent($this->user->getId(), 60) >= $limit) {
            throw new Deny('rate_limited', 429, ['retryAfter' => 10]);
        }
    }

    /** One RateLimited row per device per minute is evidence enough; more would flood the log. */
    private function shouldLogRateLimit(): bool
    {
        return $this->entityManager->getRDBRepository('KioskEvent')
            ->where([
                'deviceId' => $this->user->getId(),
                'outcome' => 'RateLimited',
                'createdAt>=' => gmdate('Y-m-d H:i:s', time() - 60),
            ])
            ->count() === 0;
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private function record(
        Request $request,
        string $action,
        string $outcome,
        int $status,
        stdClass $ctx,
        ?string $clientActionId,
        stdClass $body,
        ?array $result,
        ?string $errorCode
    ): void {
        // A failed attempt may have been recorded by a twin request a moment ago.
        $this->audit->record([
            'action' => $action,
            'outcome' => $outcome,
            'errorCode' => $errorCode,
            'httpStatus' => $status,
            'employeeId' => $ctx->employeeId ?? null,
            'deviceId' => $this->user->getId(),
            'washOrderId' => $ctx->washOrderId ?? null,
            'orderWorkId' => $ctx->orderWorkId ?? null,
            'clientActionId' => $clientActionId,
            'clientAt' => isset($body->clientAt) ? (string) $body->clientAt : null,
            'meta' => $ctx->meta ?? null,
            'result' => $result,
            'ip' => $this->ip($request),
        ]);
    }

    private function replay(Response $response, Entity $event): ?stdClass
    {
        $stored = json_decode((string) $event->get('resultJson'), true) ?: [];
        $status = (int) ($event->get('httpStatus') ?: 200);

        if ($status >= 400) {
            $stored['replayed'] = true;
            $error = (string) ($stored['error'] ?? $event->get('errorCode') ?? 'error');
            unset($stored['ok'], $stored['error']);

            return $this->refuse($response, $status, $error, $stored, true);
        }

        $stored['replayed'] = true;

        return (object) $stored;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function refuse(Response $response, int $status, string $error, array $params, bool $replayed = false): null
    {
        $response->setStatus($status);
        $response->setHeader('Content-Type', 'application/json');

        if ($status === 429 && isset($params['retryAfter'])) {
            $response->setHeader('Retry-After', (string) (int) $params['retryAfter']);
        }

        $response->writeBody(json_encode(['ok' => false, 'error' => $error] + $params + ($replayed ? ['replayed' => true] : []), JSON_UNESCAPED_UNICODE));

        return null;
    }

    private function ip(Request $request): ?string
    {
        $ip = $request->getHeader('X-Real-IP') ?: $request->getServerParam('REMOTE_ADDR');

        return $ip ? substr((string) $ip, 0, 64) : null;
    }

    /** The audit row of a work action points at the line AND its order. */
    private function refsOfLine(stdClass $ctx): void
    {
        $line = $ctx->orderWorkId !== '' ? $this->entityManager->getEntityById('OrderWork', $ctx->orderWorkId) : null;
        $ctx->washOrderId = $line ? $line->get('washOrderId') : null;
    }

    /**
     * @throws Forbidden
     */
    private function requireAdmin(): void
    {
        if (!$this->user->isAdmin() || $this->user->isApi()) {
            throw new Forbidden('Administrators only.');
        }
    }

    /** @return array<string, mixed> */
    private function employeeView(Entity $employee): array
    {
        return [
            'id' => $employee->getId(),
            'name' => (string) $employee->get('name'),
            'language' => (string) ($employee->get('language') ?: 'lv_LV'),
            'onShift' => (bool) $employee->get('onShift'),
            'shiftStartedAt' => $employee->get('shiftStartedAt'),
            'isAdmin' => $employee->get('position') === 'Administrator',
            'workRoleId' => $employee->get('workRoleId'),
        ];
    }

    /** @return array<string, mixed> */
    private function clientConfig(): array
    {
        return [
            'idleSeconds' => max(15, (int) ($this->config->get('kioskIdleSeconds') ?: 60)),
            'theme' => (string) ($this->config->get('kioskTheme') ?: 'dark'),
            'photoMaxSide' => PhotoStore::MAX_SIDE,
            'timeZone' => (string) ($this->config->get('timeZone') ?: 'UTC'),
            'pollSeconds' => 15,
        ];
    }

    /** @return array<string, mixed> */
    private function settingsView(): array
    {
        $cfg = $this->clientConfig();

        return [
            'enabledModules' => $this->config->get('enabledModules'),
            'kioskTheme' => $cfg['theme'],
            'kioskIdleSeconds' => $cfg['idleSeconds'],
            'kioskSessionHours' => $this->sessions->hours(),
            'kioskPinMaxAttempts' => $this->pin->maxAttempts(),
            'kioskPinLockMinutes' => $this->pin->lockMinutes(),
            'kioskRateLimitPerMinute' => max(10, (int) ($this->config->get('kioskRateLimitPerMinute') ?: 90)),
            'photoRetentionDays' => $this->photoStore->retentionDays(),
        ];
    }
}
