<?php

namespace Espo\Custom\Tools\Assistant;

use Espo\Core\Api\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use stdClass;

/**
 * A request made by the server to its own controller actions, so the assistant's tools run the
 * very code behind the dashlets (CarWash statistics, payroll, supply usage, customer summary,
 * Booking day) instead of repeating their queries. Only the parsed body carries data.
 */
final class InternalRequest implements Request
{
    private stdClass $body;

    /** @param array<string, mixed> $body */
    public function __construct(array $body = [])
    {
        $this->body = (object) $body;
    }

    public function hasQueryParam(string $name): bool { return false; }
    public function getQueryParam(string $name): ?string { return null; }
    public function getQueryParams(): array { return []; }
    public function hasRouteParam(string $name): bool { return false; }
    public function getRouteParam(string $name): ?string { return null; }
    public function getRouteParams(): array { return []; }
    public function getHeader(string $name): ?string { return null; }
    public function hasHeader(string $name): bool { return false; }
    public function getHeaderAsArray(string $name): array { return []; }
    public function getMethod(): string { return 'POST'; }
    public function getUri(): UriInterface { return new Uri('/'); }
    public function getResourcePath(): string { return '/'; }
    public function getBodyContents(): ?string { return json_encode($this->body); }
    public function getParsedBody(): stdClass { return $this->body; }
    public function getCookieParam(string $name): ?string { return null; }
    public function getServerParam(string $name) { return null; }
}
