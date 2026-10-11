<?php

namespace Espo\Custom\Tools\ClientAssistant;

use Espo\Core\Api\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** A Response that only remembers what a controller action wrote (status + JSON body). */
final class CaptureResponse implements Response
{
    private int $status = 200;
    private string $reason = '';
    /** @var array<string, string[]> */
    private array $headers = [];
    private string $body = '';

    public function getStatusCode(): int { return $this->status; }
    public function getReasonPhrase(): string { return $this->reason; }

    public function setStatus(int $code, ?string $reason = null): self
    {
        $this->status = $code;
        $this->reason = (string) $reason;

        return $this;
    }

    public function setHeader(string $name, string $value): self { $this->headers[strtolower($name)] = [$value]; return $this; }
    public function addHeader(string $name, string $value): self { $this->headers[strtolower($name)][] = $value; return $this; }
    public function getHeader(string $name): ?string { return $this->headers[strtolower($name)][0] ?? null; }
    public function hasHeader(string $name): bool { return isset($this->headers[strtolower($name)]); }
    public function getHeaderNames(): array { return array_keys($this->headers); }
    public function getHeaderAsArray(string $name): array { return $this->headers[strtolower($name)] ?? []; }
    public function writeBody(string $string): self { $this->body .= $string; return $this; }
    public function setBody(StreamInterface $body): self { $this->body = (string) $body; return $this; }
    public function getBody(): StreamInterface { return Utils::streamFor($this->body); }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return json_decode($this->body, true) ?: [];
    }
}
