<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Anpr\Commands;
use Espo\Custom\Tools\Anpr\EdgeAuth;
use Espo\Custom\Tools\Anpr\Ingest;
use Espo\Custom\Tools\Anpr\Respond;
use Espo\Custom\Tools\Anpr\Settings;
use Espo\Custom\Tools\Booking\RateLimiter;
use Espo\Custom\Tools\Kiosk\Deny;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * The edge node's side of the plate / camera modules. These are PUBLIC routes (routes.json,
 * noAuth: the node has no login), reachable under their documented names:
 *
 *   POST /api/v1/Anpr/action/register       { enrollToken, version? }   -> { token }   (one-time)
 *   POST /api/v1/Anpr/action/event          { eventId, camera, plateRaw, confidence, ... }
 *   POST /api/v1/Anpr/action/heartbeat      { version, queueDepth, cameras[] }
 *   POST /api/v1/Anpr/action/config         {}                           -> cameras, thresholds
 *   POST /api/v1/Anpr/action/commands       {}                           -> snapshot / clip jobs
 *   POST /api/v1/Anpr/action/commandResult  { commandId, ok, snapshotBase64 | clips }
 *
 * Every call except `register` carries `X-Edge-Token`. The node only ever connects OUT to the CRM.
 * Order of checks: module flag (403 module_disabled) -> lock-out of an IP that keeps sending bad
 * tokens (429) -> token (401 invalid_token / token_revoked) -> per-node rate limit (429) -> the
 * action. Refusals are `{ok:false, error:<code>}`; a 4xx is final, the node drops that event.
 * A public action runs as the system user, so every answer is built by hand.
 */
class AnprEdge
{
    use Respond;

    private const BAD_TOKEN_LIMIT = 20;
    private const BAD_TOKEN_WINDOW = 300;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log,
        private Modules $modules,
        private EdgeAuth $auth,
        private Ingest $ingest,
        private Commands $commands,
        private Settings $settings,
        private RateLimiter $limiter,
        private Crypt $crypt
    ) {}

    public function postActionRegister(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, ['anpr', 'cameras'], false, function (Request $r, ?Entity $node, stdClass $b) {
            $enrolled = $this->auth->enroll((string) ($b->enrollToken ?? ''));
            $this->touch($enrolled['node'], $b, $this->clientIp($r));

            return [
                'ok' => true,
                'token' => $enrolled['token'],
                'node' => ['id' => $enrolled['node']->getId(), 'name' => (string) $enrolled['node']->get('name')],
                'serverTime' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        });
    }

    public function postActionEvent(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, ['anpr'], true, function (Request $r, ?Entity $node, stdClass $b) {
            $limit = $this->settings->rateLimitPerMinute();

            if (!$this->limiter->hit('anpr-node', $node->getId(), $limit, 60)) {
                throw new Deny('rate_limited', 429, ['retryAfter' => 10]);
            }

            return $this->ingest->handle($node, $b, $this->clientIp($r));
        });
    }

    public function postActionHeartbeat(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, ['anpr', 'cameras'], true, function (Request $r, ?Entity $node, stdClass $b) {
            $this->touch($node, $b, $this->clientIp($r));

            foreach ((is_array($b->cameras ?? null) ? $b->cameras : []) as $c) {
                $c = (object) $c;
                $cam = $this->entityManager->getRDBRepository('Camera')
                    ->where(['code' => strtolower((string) ($c->code ?? '')), 'edgeNodeId' => $node->getId(), 'deleted' => false])
                    ->findOne();

                if (!$cam) {
                    continue;
                }

                $seen = isset($c->lastFrameAt) && strtotime((string) $c->lastFrameAt) ? gmdate('Y-m-d H:i:s', strtotime((string) $c->lastFrameAt)) : $cam->get('lastFrameAt');
                $cam->set([
                    'online' => !empty($c->online),
                    'lastFrameAt' => $seen,
                    'lastError' => !empty($c->online) ? null : substr(strip_tags((string) ($c->error ?? '')), 0, 190),
                ]);
                $this->entityManager->saveEntity($cam, ['silent' => true]);
            }

            return [
                'ok' => true,
                'serverTime' => gmdate('Y-m-d\TH:i:s\Z'),
                'commands' => $this->commands->pendingCount($node),
                'configVersion' => $this->configView($node)['configVersion'],
            ];
        });
    }

    public function postActionConfig(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, ['anpr', 'cameras'], true, function (Request $r, ?Entity $node) {
            return ['ok' => true] + $this->configView($node);
        });
    }

    public function postActionCommands(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, ['cameras'], true, function (Request $r, ?Entity $node) {
            $this->touch($node, new stdClass(), $this->clientIp($r), false);

            return ['ok' => true, 'commands' => $this->commands->lease($node)];
        });
    }

    public function postActionCommandResult(Request $request, Response $response): ?stdClass
    {
        return $this->run($request, $response, ['cameras'], true, function (Request $r, ?Entity $node, stdClass $b) {
            $limit = $this->settings->rateLimitPerMinute();

            if (!$this->limiter->hit('anpr-node', $node->getId(), $limit, 60)) {
                throw new Deny('rate_limited', 429, ['retryAfter' => 10]);
            }

            $c = $this->commands->complete($node, $b);

            return ['ok' => true, 'commandId' => $c->getId(), 'status' => (string) $c->get('status')];
        });
    }

    /**
     * @param array<int, string> $modules the call is allowed when ANY of these is on
     * @param callable(Request, ?Entity, stdClass): array<string, mixed> $fn
     */
    private function run(Request $request, Response $response, array $modules, bool $needsToken, callable $fn): ?stdClass
    {
        try {
            $on = false;

            foreach ($modules as $m) {
                $on = $on || $this->modules->moduleEnabled($m);
            }

            if (!$on) {
                throw new Deny('module_disabled', 403);
            }

            $ip = $this->clientIp($request) ?? 'unknown';

            if ($this->limiter->exceeded('anpr-badtoken', $ip, self::BAD_TOKEN_LIMIT, self::BAD_TOKEN_WINDOW)) {
                throw new Deny('too_many_attempts', 429, ['retryAfter' => 60]);
            }

            $node = null;

            try {
                if ($needsToken) {
                    $node = $this->auth->authenticate($request->getHeader('X-Edge-Token'));
                }

                $body = $request->getParsedBody() ?: new stdClass();

                if (!($body instanceof stdClass)) {
                    $body = new stdClass();
                }

                $out = $fn($request, $node, $body);
            } catch (Deny $d) {
                if (in_array($d->errorCode(), ['invalid_token', 'token_revoked', 'invalid_enroll_token', 'enroll_token_expired'], true)) {
                    $this->limiter->hit('anpr-badtoken', $ip, self::BAD_TOKEN_LIMIT, self::BAD_TOKEN_WINDOW);
                }

                throw $d;
            }

            $response->setHeader('Content-Type', 'application/json');
            $response->setHeader('Cache-Control', 'no-store');

            return (object) $out;
        } catch (Deny $d) {
            return $this->refuse($response, $d->status(), $d->errorCode(), $d->params());
        } catch (\Throwable $e) {
            // never the token, never the payload
            $this->log->error('AnprEdge: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->refuse($response, 500, 'server_error');
        }
    }

    private function touch(Entity $node, stdClass $b, ?string $ip, bool $full = true): void
    {
        $attrs = ['lastSeenAt' => gmdate('Y-m-d H:i:s'), 'lastIp' => $ip];

        if ($full) {
            if (isset($b->version)) {
                $attrs['version'] = substr(preg_replace('/[^A-Za-z0-9._+\-]/', '', (string) $b->version) ?? '', 0, 40);
            }

            if (isset($b->queueDepth) && is_numeric($b->queueDepth)) {
                $attrs['queueDepth'] = max(0, (int) $b->queueDepth);
            }

            if (isset($b->now) && strtotime((string) $b->now)) {
                $attrs['clockSkewSeconds'] = (int) (strtotime((string) $b->now) - time());
            }

            if (isset($b->cameras) && is_array($b->cameras)) {
                $attrs['report'] = substr((string) json_encode($b->cameras, JSON_UNESCAPED_UNICODE), 0, 4000);
            }
        }

        $node->set($attrs);
        $this->entityManager->saveEntity($node, ['silent' => true]);
    }

    /**
     * What the node needs to run: its cameras, the thresholds, the timings. Camera secrets are
     * sent to the node that owns the camera (it has to log in to the camera) and nowhere else.
     *
     * @return array<string, mixed>
     */
    private function configView(Entity $node): array
    {
        $cameras = [];
        $rows = $this->entityManager->getRDBRepository('Camera')
            ->where(['edgeNodeId' => $node->getId(), 'deleted' => false])
            ->order('name', 'ASC')
            ->find();

        foreach ($rows as $c) {
            $secret = null;

            if ($c->get('secretCipher')) {
                try {
                    $secret = $this->crypt->decrypt((string) $c->get('secretCipher'));
                } catch (\Throwable) {
                    $secret = null;
                }
            }

            $cameras[] = [
                'code' => (string) $c->get('code'),
                'name' => (string) $c->get('name'),
                'role' => (string) $c->get('role'),
                'streamName' => (string) $c->get('streamName'),
                'active' => (bool) $c->get('isActive'),
                'secret' => $secret,
            ];
        }

        $view = [
            'node' => ['id' => $node->getId(), 'name' => (string) $node->get('name')],
            'serverTime' => gmdate('Y-m-d\TH:i:s\Z'),
            'cameras' => $cameras,
            'thresholds' => [
                'minConfidence' => $this->settings->minConfidence(),
                'highConfidence' => $this->settings->highConfidence(),
            ],
            'dedupSeconds' => $this->settings->dedupSeconds(),
            'heartbeatSeconds' => 30,
            'commandPollSeconds' => 3,
            'maxEventAgeHours' => $this->settings->maxEventAgeHours(),
            'rateLimitPerMinute' => $this->settings->rateLimitPerMinute(),
        ];

        // The version covers everything the node acts on (the secret only as a hash).
        $stable = $view;
        unset($stable['serverTime']);

        foreach ($stable['cameras'] as &$c) {
            $c['secret'] = $c['secret'] === null ? null : substr(hash('sha256', $c['secret']), 0, 8);
        }

        $view['configVersion'] = substr(hash('sha256', (string) json_encode($stable)), 0, 16);

        return $view;
    }
}
