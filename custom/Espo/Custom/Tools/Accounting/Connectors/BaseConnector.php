<?php

namespace Espo\Custom\Tools\Accounting\Connectors;

use Espo\Custom\Tools\Accounting\Cfg;
use Espo\Custom\Tools\Accounting\ConnectorException;

/** Shared error handling: what an HTTP answer means for the retry policy. */
abstract class BaseConnector implements Connector
{
    public function __construct(protected Cfg $cfg)
    {}

    /** @param array{code: int, body: string, errno: int} $r */
    protected function check(array $r): void
    {
        if ($r['errno'] !== 0 || $r['code'] === 0) {
            // timeout / DNS / TLS: the provider may be fine, ours or their network is not - try again later
            throw new ConnectorException('network_error', true);
        }

        $c = $r['code'];

        if ($c === 429) {
            throw new ConnectorException('rate_limited', true, true);
        }

        if ($c === 503) { // SmartAccounts: rate limit AND billing problem both answer 503
            throw new ConnectorException('provider_unavailable_503', true, true);
        }

        if ($c >= 500) {
            throw new ConnectorException('provider_error_' . $c, true);
        }

        if ($c === 401 || $c === 403) {
            throw new ConnectorException('unauthorized_' . $c . ': ' . $this->snippet($r['body']), false);
        }

        if ($c >= 400) {
            throw new ConnectorException('rejected_' . $c . ': ' . $this->snippet($r['body']), false);
        }
    }

    protected function snippet(string $body): string
    {
        $t = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return $this->cfg->scrub(mb_substr($t, 0, 240));
    }

    /** @return mixed decoded JSON; a non-JSON 200 (e.g. an ASP.NET stack trace) is a permanent failure */
    protected function json(array $r): mixed
    {
        $d = json_decode($r['body'], true);

        if ($d === null && trim($r['body']) !== 'null') {
            throw new ConnectorException('not_json_answer: ' . $this->snippet($r['body']), false);
        }

        return $d;
    }

    /** All associative arrays in a decoded answer that carry $key (the answer wrappers of both programs are not fixed in the docs). */
    protected function collect(mixed $d, string $key, array &$out = []): array
    {
        if (is_array($d)) {
            if (array_key_exists($key, $d) && !array_is_list($d)) {
                $out[] = $d;
            }

            foreach ($d as $v) {
                if (is_array($v)) {
                    $this->collect($v, $key, $out);
                }
            }
        }

        return $out;
    }

    protected function money(float $v): float
    {
        return round($v, 2);
    }
}
