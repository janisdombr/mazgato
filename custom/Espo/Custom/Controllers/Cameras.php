<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Anpr\Commands;
use Espo\Custom\Tools\Anpr\MediaLog;
use Espo\Custom\Tools\Anpr\Respond;
use Espo\Custom\Tools\Anpr\Settings;
use Espo\Custom\Tools\Anpr\StreamToken;
use Espo\Custom\Tools\Booking\RateLimiter;
use Espo\Custom\Tools\Kiosk\Deny;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Cameras, the operator's side. POST /api/v1/Cameras/action/<name>
 *
 *   list           the cameras the user may see, with node / camera health
 *   snapshot       "a frame now": queues a command the edge node fetches by outbound polling
 *   commandStatus  the answer to a snapshot / clips command (poll until Done)
 *   clips          recordings around a time, as listed by the NVR through the node
 *   streamToken    a 5-minute pass for the embedded live viewer (cookie + url)
 *
 * Module `cameras` off -> 403 module_disabled. Needs the Camera scope (read). Every look is
 * journalled (MediaAccess). A device key is refused. Camera secrets are never returned here.
 */
class Cameras
{
    use Respond;

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Acl $acl,
        private Log $log,
        private Modules $modules,
        private Settings $settings,
        private Commands $commands,
        private MediaLog $mediaLog,
        private StreamToken $streamToken,
        private RateLimiter $limiter,
        DeviceGuard $deviceGuard
    ) {
        $deviceGuard->denyDevice($user);
    }

    public function postActionList(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () {
            $this->gate('read');
            $out = [];

            foreach ($this->entityManager->getRDBRepository('Camera')->where(['deleted' => false])->order('name', 'ASC')->find() as $c) {
                if ($this->acl->checkEntityRead($c)) {
                    $out[] = $this->view($c);
                }
            }

            return ['list' => $out, 'viewBase' => $this->settings->viewBase()];
        });
    }

    public function postActionSnapshot(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $camera = $this->camera($request, 'read');
            $this->online($camera);

            if (!$this->limiter->hit('cam-snapshot', $this->user->getId(), 12, 60)) {
                throw new Deny('rate_limited', 429, ['retryAfter' => 10]);
            }

            $cmd = $this->commands->enqueue($camera, 'Snapshot', $this->user->getId());
            $this->mediaLog->record('Snapshot', $this->user->getId(), ['cameraId' => $camera->getId(), 'detail' => 'request', 'ip' => $this->clientIp($request), 'always' => true]);

            return ['ok' => true, 'commandId' => $cmd->getId(), 'status' => 'Queued'];
        });
    }

    public function postActionClips(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $camera = $this->camera($request, 'read');
            $this->online($camera);
            $b = $request->getParsedBody() ?: new stdClass();

            $from = isset($b->from) && strtotime((string) $b->from) ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $b->from)) : gmdate('Y-m-d\TH:i:s\Z', time() - 1800);
            $to = isset($b->to) && strtotime((string) $b->to) ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $b->to)) : gmdate('Y-m-d\TH:i:s\Z');

            if (strtotime($to) <= strtotime($from) || strtotime($to) - strtotime($from) > 6 * 3600) {
                throw new Deny('range_invalid', 422);
            }

            $cmd = $this->commands->enqueue($camera, 'Clips', $this->user->getId(), ['from' => $from, 'to' => $to]);
            $this->mediaLog->record('Clip', $this->user->getId(), ['cameraId' => $camera->getId(), 'detail' => $from . ' .. ' . $to, 'ip' => $this->clientIp($request), 'always' => true]);

            return ['ok' => true, 'commandId' => $cmd->getId(), 'status' => 'Queued'];
        });
    }

    public function postActionCommandStatus(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request) {
            $this->gate('read');
            $this->commands->expireOld();
            $b = $request->getParsedBody() ?: new stdClass();
            $c = $this->entityManager->getEntityById('CameraCommand', (string) ($b->commandId ?? ''));
            $camera = $c && $c->get('cameraId') ? $this->entityManager->getEntityById('Camera', (string) $c->get('cameraId')) : null;

            if (!$c || !$camera || !$this->acl->checkEntityRead($camera)) {
                throw new Deny('not_found', 404);
            }

            return [
                'ok' => true,
                'commandId' => $c->getId(),
                'type' => (string) $c->get('type'),
                'status' => (string) $c->get('status'),
                'error' => $c->get('error'),
                'imageUrl' => $c->get('hasFile') ? '?entryPoint=cwMedia&type=command&id=' . $c->getId() : null,
                'clips' => $c->get('result') ? json_decode((string) $c->get('result'), true) : [],
                'viewBase' => $this->settings->viewBase(),
            ];
        });
    }

    /** The pass for the embedded viewer: a cookie scoped to the viewer path, and the iframe address. */
    public function postActionStreamToken(Request $request, Response $response): ?stdClass
    {
        return $this->guard($response, function () use ($request, $response) {
            $camera = $this->camera($request, 'read');
            $stream = (string) $camera->get('streamName');

            if ($stream === '') {
                throw new Deny('no_stream', 422);
            }

            $pass = $this->streamToken->issue($this->user->getId(), $stream);
            $base = $this->settings->viewBase();
            $path = parse_url($base, PHP_URL_PATH) ?: '/cam';

            // Scoped to the viewer's path, not readable by scripts, not sent cross-site.
            $response->setHeader('Set-Cookie', sprintf(
                '%s=%s; Path=%s; Max-Age=%d; HttpOnly; SameSite=Strict%s',
                StreamToken::COOKIE,
                $pass['token'],
                $path,
                StreamToken::TTL,
                $request->getHeader('X-Forwarded-Proto') === 'https' ? '; Secure' : ''
            ));

            $this->mediaLog->record('Live', $this->user->getId(), ['cameraId' => $camera->getId(), 'ip' => $this->clientIp($request)]);

            return [
                'ok' => true,
                'url' => $base . '/stream.html?src=' . rawurlencode($stream) . '&mode=mse',
                'token' => $pass['token'],
                'expiresAt' => $pass['expiresAt'],
                'ttl' => StreamToken::TTL,
            ];
        });
    }

    // ------------------------------------------------------------------ helpers

    /** @param callable(): array<string, mixed> $fn */
    private function guard(Response $response, callable $fn): ?stdClass
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
            $this->log->error('Cameras: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->refuse($response, 500, 'server_error');
        }
    }

    /** @throws Deny */
    private function gate(string $action): void
    {
        if (!$this->modules->moduleEnabled(Modules::CAMERAS)) {
            throw new Deny('module_disabled', 403);
        }

        if (!$this->acl->check('Camera', $action)) {
            throw new Deny('forbidden', 403);
        }
    }

    /** @throws Deny */
    private function camera(Request $request, string $action): Entity
    {
        $this->gate($action);
        $b = $request->getParsedBody() ?: new stdClass();
        $camera = $this->entityManager->getEntityById('Camera', (string) ($b->cameraId ?? ''));

        if (!$camera || !$this->acl->checkEntityRead($camera)) {
            throw new Deny('not_found', 404);
        }

        if (!$camera->get('isActive')) {
            throw new Deny('camera_inactive', 409);
        }

        return $camera;
    }

    /** @throws Deny */
    private function online(Entity $camera): void
    {
        $node = $camera->get('edgeNodeId') ? $this->entityManager->getEntityById('EdgeNode', (string) $camera->get('edgeNodeId')) : null;
        $seen = $node ? $node->get('lastSeenAt') : null;

        if (!$node || $node->get('status') !== 'Active' || !$seen || strtotime($seen . ' UTC') < time() - $this->settings->nodeOfflineSeconds()) {
            throw new Deny('node_offline', 503);
        }
    }

    /** @return array<string, mixed> */
    private function view(Entity $c): array
    {
        $node = $c->get('edgeNodeId') ? $this->entityManager->getEntityById('EdgeNode', (string) $c->get('edgeNodeId')) : null;
        $seen = $node ? $node->get('lastSeenAt') : null;
        $nodeOnline = $node && $node->get('status') === 'Active' && $seen && strtotime($seen . ' UTC') > time() - $this->settings->nodeOfflineSeconds();

        return [
            'id' => $c->getId(),
            'name' => (string) $c->get('name'),
            'code' => (string) $c->get('code'),
            'role' => (string) $c->get('role'),
            'post' => $c->get('post'),
            'isActive' => (bool) $c->get('isActive'),
            'hasStream' => (string) $c->get('streamName') !== '',
            'nodeOnline' => (bool) $nodeOnline,
            'online' => (bool) $c->get('online') && $nodeOnline,
            'lastFrameAt' => $c->get('lastFrameAt'),
        ];
    }
}
