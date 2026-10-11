<?php

namespace Espo\Custom\Tools\Notify;

use Espo\Core\Mail\EmailSender;
use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Email through the system SMTP — the same path the Mailing feature uses. */
class EmailChannel implements Channel
{
    public function __construct(
        private EmailSender $sender,
        private EntityManager $em,
        private Config $config
    ) {}

    public function name(): string
    {
        return 'Email';
    }

    public function ready(): bool
    {
        return $this->config->get('notifyEmailOn', true) && $this->sender->hasSystemSmtp();
    }

    public function address(Entity $customer): string
    {
        return trim((string) $customer->get('email'));
    }

    public function send(string $to, string $text, array $ctx = []): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid email address');
        }

        $email = $this->em->createEntity('Email', [
            'to' => $to,
            'subject' => (string) ($ctx['subject'] ?? ''),
            'body' => $text,
            'isHtml' => false,
        ]);
        $this->sender->create()->send($email);
    }
}
