<?php

namespace Espo\Custom\Tools\Notify;

use Espo\ORM\Entity;

/**
 * One delivery channel for service messages. Adapters are interchangeable: the Notifier only
 * asks "do you have an address for this customer?", "are you switched on and configured?" and
 * "send this text". A failure is an exception; the Notifier records it and never lets it out.
 */
interface Channel
{
    /** Stable name stored on OrderMessage.channel: Email | WhatsApp | Sms | Telegram. */
    public function name(): string;

    /** Switched on in Settings AND has the credentials it needs. */
    public function ready(): bool;

    /** The customer's address on this channel ('' = none). */
    public function address(Entity $customer): string;

    /**
     * @param array<string, mixed> $ctx subject, kind, fallbackEmail
     * @throws \Throwable
     */
    public function send(string $to, string $text, array $ctx = []): void;
}
