<?php

namespace Espo\Custom\Tools\Accounting;

/** One HTTP call with hard timeouts. Returns the answer, never throws; the caller decides what a code means. */
final class Http
{
    /**
     * @param list<string> $headers "Name: value" lines
     * @return array{code: int, body: string, errno: int}
     */
    public static function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => 5 * 1024 * 1024,
            CURLOPT_USERAGENT => 'carwash-crm-accounting/1',
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        return ['code' => $code, 'body' => is_string($out) ? $out : '', 'errno' => $errno];
    }
}
