<?php

namespace Espo\Custom\Tools\ClientAssistant;

use Espo\Core\Api\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use stdClass;

/**
 * The visitor's own request replayed inside the server, so the booking code sees the real client
 * address (its per-IP limits keep working) and the form token the visitor's page was issued.
 */
final class ForwardedRequest implements Request
{
    private stdClass $body;

    /** @param array<string, mixed> $body */
    public function __construct(array $body, private string $ip)
    {
        $this->body = (object) $body;
    }

    public function hasQueryParam(string $name): bool { return false; }
    public function getQueryParam(string $name): ?string { return null; }
    public function getQueryParams(): array { return []; }
    public function hasRouteParam(string $name): bool { return false; }
    public function getRouteParam(string $name): ?string { return null; }
    public function getRouteParams(): array { return []; }
    public function getHeader(string $name): ?string { return strtolower($name) === 'x-real-ip' ? $this->ip : null; }
    public function hasHeader(string $name): bool { return strtolower($name) === 'x-real-ip'; }
    public function getHeaderAsArray(string $name): array { return strtolower($name) === 'x-real-ip' ? [$this->ip] : []; }
    public function getMethod(): string { return 'POST'; }
    public function getUri(): UriInterface { return new Uri('/'); }
    public function getResourcePath(): string { return '/'; }
    public function getBodyContents(): ?string { return json_encode($this->body); }
    public function getParsedBody(): stdClass { return $this->body; }
    public function getCookieParam(string $name): ?string { return null; }
    public function getServerParam(string $name) { return $name === 'REMOTE_ADDR' ? $this->ip : null; }
}
