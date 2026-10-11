<?php

namespace Espo\Custom\Tools\Notify;

use Espo\Core\Utils\Config;
use Espo\ORM\EntityManager;

/**
 * Inbound webhook for replies. `POST /api/v1/Notify/inbound/telegram|sms|whatsapp` (public route, see routes.json).
 *
 *  - telegram: Telegram Bot API update. Authorised by the secret_token header
 *    (setWebhook secret_token = config telegramWebhookSecret). `/start <token>` binds the chat
 *    to the Customer whose telegramLinkToken matches; `/stop` or `STOP` switches service
 *    messages off.
 *  - sms / whatsapp: `STOP` from a phone switches that customer off. Authorised by
 *    `secret=` (config notifyInboundSecret). Accepts {from,text} (generic SMS provider hook)
 *    or the Meta webhook body.
 * Without a configured secret the endpoint answers 403 — it is never open.
 */
class Inbound
{
    public function __construct(
        private EntityManager $em,
        private Config $config,
        private Notifier $notifier
    ) {}

    /**
     * @param array<string, mixed> $body decoded JSON
     * @return int HTTP status
     */
    public function handle(string $channel, array $body, string $header, string $querySecret): int
    {
        $secret = (string) $this->config->get($channel === 'telegram' ? 'telegramWebhookSecret' : 'notifyInboundSecret', '');
        $given = $channel === 'telegram' ? $header : $querySecret;

        if ($secret === '' || !hash_equals($secret, $given)) {
            return 403;
        }

        try {
            if ($channel === 'telegram') {
                $this->telegram($body);
            } elseif ($channel === 'sms' || $channel === 'whatsapp') {
                $this->phoneStop($body);
            }
        } catch (\Throwable $e) {
            // Never make the provider retry-storm us.
        }

        return 200;
    }

    /** @param array<string, mixed> $u */
    private function telegram(array $u): void
    {
        $m = $u['message'] ?? null;
        $chat = (string) ($m['chat']['id'] ?? '');
        $text = trim((string) ($m['text'] ?? ''));

        if ($chat === '' || $text === '') {
            return;
        }

        if (preg_match('/^\/start\s+([A-Za-z0-9]{8,40})$/', $text, $mm)) {
            $c = $this->em->getRDBRepository('Customer')->where(['telegramLinkToken' => $mm[1], 'deleted' => false])->findOne();

            if ($c) {
                $c->set(['telegramChatId' => $chat, 'telegramLinkToken' => null]);
                $this->em->saveEntity($c, ['skipHooks' => true]);
                $this->reply($chat, 'OK');
            }

            return;
        }

        if (preg_match('/^(\/stop|stop)$/i', $text)) {
            $c = $this->em->getRDBRepository('Customer')->where(['telegramChatId' => $chat, 'deleted' => false])->findOne();

            if ($c) {
                $this->off($c);
                $this->reply($chat, 'OK');
            }
        }
    }

    /** @param array<string, mixed> $b */
    private function phoneStop(array $b): void
    {
        $from = (string) ($b['from'] ?? '');
        $text = (string) ($b['text'] ?? '');
        $msg = $b['entry'][0]['changes'][0]['value']['messages'][0] ?? null;

        if ($msg) {
            $from = (string) ($msg['from'] ?? '');
            $text = (string) ($msg['text']['body'] ?? '');
        }

        if (!preg_match('/^\s*stop\s*$/i', $text)) {
            return;
        }

        $digits = Http::phoneDigits($from, (string) $this->config->get('notifyCountryCode', '371'));

        if (strlen($digits) < 8) {
            return;
        }

        foreach ($this->em->getRDBRepository('Customer')->where(['phone*' => '%' . substr($digits, -8) . '%', 'deleted' => false])->find() as $c) {
            if (substr(preg_replace('/\D+/', '', (string) $c->get('phone')) ?? '', -8) === substr($digits, -8)) {
                $this->off($c);
            }
        }
    }

    private function off(\Espo\ORM\Entity $c): void
    {
        if ($c->get('allowServiceMessages') !== false) {
            $c->set(['allowServiceMessages' => false, 'serviceMessagesOffAt' => date('Y-m-d H:i:s')]);
            $this->em->saveEntity($c, ['skipHooks' => true]);
        }
    }

    private function reply(string $chat, string $text): void
    {
        try {
            if ($this->notifier->telegram->ready()) {
                $this->notifier->telegram->send($chat, $text);
            }
        } catch (\Throwable $e) {
        }
    }
}
