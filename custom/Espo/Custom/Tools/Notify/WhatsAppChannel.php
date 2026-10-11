<?php

namespace Espo\Custom\Tools\Notify;

use Espo\Core\Utils\Config;
use Espo\ORM\Entity;

/**
 * WhatsApp Cloud API (docs/WHATSAPP.md). Needs whatsappPhoneNumberId + whatsappAccessToken.
 * Outside the 24-hour window Meta only accepts an approved template: when
 * `whatsappNotifyTemplate` is set the whole message goes in as its single {{1}} variable,
 * otherwise a plain text message is sent (works inside an open window only).
 */
class WhatsAppChannel implements Channel
{
    public function __construct(private Config $config, private Http $http) {}

    public function name(): string
    {
        return 'WhatsApp';
    }

    public function ready(): bool
    {
        return (bool) $this->config->get('notifyWhatsappOn', false)
            && trim((string) $this->config->get('whatsappPhoneNumberId', '')) !== ''
            && trim((string) $this->config->get('whatsappAccessToken', '')) !== '';
    }

    public function address(Entity $customer): string
    {
        return Http::phoneDigits((string) $customer->get('phone'), (string) $this->config->get('notifyCountryCode', '371'));
    }

    public function send(string $to, string $text, array $ctx = []): void
    {
        $base = rtrim((string) $this->config->get('whatsappApiBase', 'https://graph.facebook.com/v21.0'), '/');
        $tpl = trim((string) $this->config->get('whatsappNotifyTemplate', ''));

        $payload = ['messaging_product' => 'whatsapp', 'to' => $to];

        if ($tpl !== '') {
            $payload['type'] = 'template';
            $payload['template'] = [
                'name' => $tpl,
                'language' => ['code' => (string) $this->config->get('whatsappTemplateLang', 'lv')],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => preg_replace('/\s*\n+\s*/', ' | ', $text)]],
                ]],
            ];
        } else {
            $payload['type'] = 'text';
            $payload['text'] = ['body' => $text];
        }

        [$code, $body] = $this->http->request(
            'POST',
            $base . '/' . trim((string) $this->config->get('whatsappPhoneNumberId')) . '/messages',
            [
                'Authorization: Bearer ' . $this->config->get('whatsappAccessToken'),
                'Content-Type: application/json',
            ],
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('WhatsApp HTTP ' . $code . ': ' . mb_substr($body, 0, 160));
        }
    }
}
