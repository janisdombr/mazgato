<?php

namespace Espo\Custom\Tools\Notify;

use Espo\Core\Utils\Config;
use Espo\ORM\Entity;

/**
 * SMS through ANY HTTP provider — no provider is built in. Settings describe the request:
 *   smsWebhookUrl, smsWebhookMethod (POST), smsWebhookFormat (json | form),
 *   smsWebhookBody  — template with {to} {text} {from} (values are escaped for the format),
 *   smsWebhookHeaders — one "Name: value" per line (secret), smsBasicAuth "user:password" (secret),
 *   smsFrom. Any 2xx answer counts as accepted. docs/NOTIFICATIONS.md has a worked example.
 */
class SmsChannel implements Channel
{
    public function __construct(private Config $config, private Http $http) {}

    public function name(): string
    {
        return 'Sms';
    }

    public function ready(): bool
    {
        return (bool) $this->config->get('notifySmsOn', false)
            && trim((string) $this->config->get('smsWebhookUrl', '')) !== '';
    }

    public function address(Entity $customer): string
    {
        return Http::phoneDigits((string) $customer->get('phone'), (string) $this->config->get('notifyCountryCode', '371'));
    }

    public function send(string $to, string $text, array $ctx = []): void
    {
        $format = (string) $this->config->get('smsWebhookFormat', 'json');
        $from = (string) $this->config->get('smsFrom', '');
        $tpl = (string) $this->config->get('smsWebhookBody', '');
        $vals = ['{to}' => '+' . $to, '{text}' => $text, '{from}' => $from];

        if ($tpl === '') {
            $tpl = $format === 'form' ? 'to={to}&from={from}&text={text}' : '{"to":"{to}","from":"{from}","text":"{text}"}';
        }

        $esc = [];

        foreach ($vals as $k => $v) {
            $esc[$k] = $format === 'form'
                ? rawurlencode($v)
                : substr((string) json_encode($v, JSON_UNESCAPED_UNICODE), 1, -1);
        }

        $headers = [$format === 'form' ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json'];

        foreach (preg_split('/\R/', (string) $this->config->get('smsWebhookHeaders', '')) ?: [] as $line) {
            if (str_contains($line, ':') && !preg_match('/[\r\n]/', $line)) {
                $headers[] = trim($line);
            }
        }

        $basic = (string) $this->config->get('smsBasicAuth', '');

        if ($basic !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($basic);
        }

        [$code, $body] = $this->http->request(
            (string) $this->config->get('smsWebhookMethod', 'POST'),
            (string) $this->config->get('smsWebhookUrl'),
            $headers,
            strtr($tpl, $esc)
        );

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('SMS gateway HTTP ' . $code . ': ' . mb_substr($body, 0, 160));
        }
    }
}
