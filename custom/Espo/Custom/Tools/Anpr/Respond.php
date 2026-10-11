<?php

namespace Espo\Custom\Tools\Anpr;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;

/**
 * JSON answers shared by the plate / camera controllers: refusals are `{ok:false, error:<code>}`
 * with the HTTP status (the same shape as the tablet's), and a 429 carries Retry-After.
 */
trait Respond
{
    /** @param array<string, mixed> $params */
    protected function refuse(Response $response, int $status, string $error, array $params = []): null
    {
        $response->setStatus($status);
        $response->setHeader('Content-Type', 'application/json');
        $response->setHeader('Cache-Control', 'no-store');

        if ($status === 429 && isset($params['retryAfter'])) {
            $response->setHeader('Retry-After', (string) (int) $params['retryAfter']);
        }

        $response->writeBody(json_encode(['ok' => false, 'error' => $error] + $params, JSON_UNESCAPED_UNICODE));

        return null;
    }

    protected function clientIp(Request $request): ?string
    {
        $ip = $request->getHeader('X-Real-IP') ?: $request->getServerParam('REMOTE_ADDR');

        return $ip ? substr((string) $ip, 0, 64) : null;
    }
}
