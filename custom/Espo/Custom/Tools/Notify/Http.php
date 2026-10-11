<?php

namespace Espo\Custom\Tools\Notify;

/** Minimal curl wrapper shared by the HTTP-based channels (short timeouts: never stall a save). */
class Http
{
    /**
     * @param string[] $headers "Name: value" lines
     * @return array{0:int,1:string}
     */
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Invalid gateway URL');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $out = curl_exec($ch);

        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);

            throw new \RuntimeException('Gateway unreachable: ' . $err);
        }

        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$code, (string) $out];
    }

    /** Digits only with a country code (8-digit local numbers get the default one). */
    public static function phoneDigits(string $phone, string $cc = '371'): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }

        return strlen($d) === 8 ? $cc . $d : $d;
    }
}
