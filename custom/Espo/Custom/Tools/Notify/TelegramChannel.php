<?php

namespace Espo\Custom\Tools\Notify;

use Espo\Core\Utils\Config;
use Espo\ORM\Entity;

/**
 * Telegram Bot API. The customer is linked by a deep link t.me/<bot>?start=<token>; the
 * webhook (entry point notifyInbound) stores the chat id on the Customer. No chat id = no address.
 */
class TelegramChannel implements Channel
{
    public function __construct(private Config $config, private Http $http) {}

    public function name(): string
    {
        return 'Telegram';
    }

    public function ready(): bool
    {
        return (bool) $this->config->get('notifyTelegramOn', false)
            && trim((string) $this->config->get('telegramBotToken', '')) !== '';
    }

    public function address(Entity $customer): string
    {
        return trim((string) $customer->get('telegramChatId'));
    }

    public function send(string $to, string $text, array $ctx = []): void
    {
        $this->call('sendMessage', ['chat_id' => $to, 'text' => $text, 'disable_web_page_preview' => true]);
    }

    /** @param array<string, mixed> $payload */
    public function call(string $method, array $payload): void
    {
        $base = rtrim((string) $this->config->get('telegramApiBase', 'https://api.telegram.org'), '/');

        [$code, $body] = $this->http->request(
            'POST',
            $base . '/bot' . $this->config->get('telegramBotToken') . '/' . $method,
            ['Content-Type: application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Telegram HTTP ' . $code . ': ' . mb_substr($body, 0, 160));
        }
    }
}
