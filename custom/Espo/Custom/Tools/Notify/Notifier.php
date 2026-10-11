<?php

namespace Espo\Custom\Tools\Notify;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Brand\Brand;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Sends a service message about one order (car ready, booking confirmed / reminder /
 * cancelled) and records EVERY attempt as an OrderMessage.
 *
 * Rules:
 *  - never throws: a channel failure must not break the order save that triggered it;
 *  - once per (order, kind): an existing Sent/Skipped record is the idempotency guard
 *    (a Failed one may be retried on another channel);
 *  - respects Customer.allowServiceMessages (explicit false = no message, recorded as Skipped);
 *    marketing consents on the Customer are not consulted — they are a different thing;
 *  - the channel is the customer's preferred one, else the first in `notifyChannelOrder` that is
 *    switched on, configured and for which the customer has an address. A failed channel falls
 *    through to the next. Nothing available = one honest Skipped record (no key / no contact).
 */
class Notifier
{
    public const KINDS = ['Ready', 'Booked', 'Reminder', 'Cancelled'];

    /** @var Channel[] */
    private array $channels;

    public function __construct(
        private EntityManager $em,
        private Config $config,
        private Log $log,
        private Brand $brand,
        private Modules $modules,
        EmailChannel $email,
        WhatsAppChannel $whatsapp,
        SmsChannel $sms,
        public TelegramChannel $telegram
    ) {
        $this->channels = [
            'Email' => $email,
            'WhatsApp' => $whatsapp,
            'Sms' => $sms,
            'Telegram' => $telegram,
        ];
    }

    /** @return Channel[] keyed by name */
    public function channels(): array
    {
        return $this->channels;
    }

    /** Candidate channel names in priority order for this customer. @return string[] */
    public function order(Entity $customer): array
    {
        $global = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->config->get('notifyChannelOrder', 'Telegram,Email,WhatsApp,Sms'))
        ), fn($n) => isset($this->channels[$n])));

        foreach (array_keys($this->channels) as $n) {
            if (!in_array($n, $global, true)) {
                $global[] = $n;
            }
        }

        $pref = (string) $customer->get('notifyChannel');

        if (isset($this->channels[$pref])) {
            array_unshift($global, $pref);
        }

        return array_values(array_unique($global));
    }

    public function stopUrl(Entity $customer, string $lang): string
    {
        $token = (string) $customer->get('unsubscribeToken');

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $customer->set('unsubscribeToken', $token);
            $this->em->saveEntity($customer, ['skipHooks' => true]);
        }

        return rtrim((string) $this->config->get('siteUrl', ''), '/') .
            '/booking/stop.html?id=' . $token . '&lang=' . substr($lang, 0, 2);
    }

    /** Compose subject + body for an order. @return array{0:string,1:string,2:string} subject, body, lang */
    public function compose(Entity $order, Entity $customer, string $kind): array
    {
        $lang = Texts::lang((string) $customer->get('language'), (string) $this->config->get('language', 'lv_LV'));

        $vehicle = $order->get('vehicleId') ? $this->em->getEntityById('Vehicle', $order->get('vehicleId')) : null;
        $tz = new \DateTimeZone((string) $this->config->get('timeZone', 'UTC'));
        $when = '';

        if ($order->get('orderDateTime')) {
            $d = new \DateTime((string) $order->get('orderDateTime'), new \DateTimeZone('UTC'));
            $when = $d->setTimezone($tz)->format('d.m.Y H:i');
        }

        $amount = '';

        if ($order->get('paymentStatus') === 'Unpaid' && (float) $order->get('finalPrice') > 0) {
            $amount = number_format((float) $order->get('finalPrice'), 2, '.', '');
        }

        $manage = '';

        if ($order->get('manageToken')) {
            $manage = rtrim((string) $this->config->get('siteUrl', ''), '/') . '/booking/?m=' .
                $order->get('manageToken') . '&lang=' . substr($lang, 0, 2);
        }

        [$subject, $body] = Texts::render($kind, $lang, [
            'brand' => $this->brand->docName(),
            'plate' => $vehicle ? (string) $vehicle->get('name') : '',
            'when' => $when,
            'amount' => $amount,
            'reviewUrl' => trim((string) $this->config->get('reviewUrl', '')),
            'manageUrl' => $manage,
            'stopUrl' => $this->stopUrl($customer, $lang),
        ]);

        return [$subject, $body, $lang];
    }

    /**
     * @return ?Entity the last OrderMessage written, or null when nothing was attempted
     */
    public function notify(Entity $order, string $kind): ?Entity
    {
        try {
            return $this->notifyInternal($order, $kind);
        } catch (\Throwable $e) {
            $this->log->error('Notify: ' . get_class($e) . ': ' . $e->getMessage());

            return null;
        }
    }

    private function notifyInternal(Entity $order, string $kind): ?Entity
    {
        if (!$this->modules->moduleEnabled(Modules::NOTIFY)) {
            return null;
        }

        $done = $this->em->getRDBRepository('OrderMessage')
            ->where(['washOrderId' => $order->getId(), 'kind' => $kind, 'status' => ['Sent', 'Skipped']])
            ->findOne();

        if ($done) {
            return $done;
        }

        $customer = $order->get('customerId') ? $this->em->getEntityById('Customer', $order->get('customerId')) : null;

        if (!$customer) {
            return $this->record($order, null, $kind, '', 'Skipped', 'no-customer', '', '');
        }

        [$subject, $body] = $this->compose($order, $customer, $kind);

        if ($customer->get('allowServiceMessages') === false) {
            return $this->record($order, $customer, $kind, '', 'Skipped', 'opted-out', '', $body);
        }

        $fallbackEmail = trim((string) $order->get('bookingEmail'));
        $firstWithAddress = null;
        $last = null;

        foreach ($this->order($customer) as $name) {
            $ch = $this->channels[$name];
            $to = $ch->address($customer);

            if ($to === '' && $name === 'Email' && $fallbackEmail !== '') {
                $to = $fallbackEmail;
            }

            if ($to === '') {
                continue;
            }

            $firstWithAddress ??= [$name, $to];

            if (!$ch->ready()) {
                continue;
            }

            try {
                $ch->send($to, $body, ['subject' => $subject, 'kind' => $kind]);

                return $this->record($order, $customer, $kind, $name, 'Sent', null, $to, $body);
            } catch (\Throwable $e) {
                $last = $this->record($order, $customer, $kind, $name, 'Failed', mb_substr($e->getMessage(), 0, 250), $to, $body);
            }
        }

        if ($last) {
            return $last;
        }

        if ($firstWithAddress) {
            return $this->record($order, $customer, $kind, $firstWithAddress[0], 'Skipped', 'not-configured', $firstWithAddress[1], $body);
        }

        return $this->record($order, $customer, $kind, '', 'Skipped', 'no-contact', '', $body);
    }

    /** Send an arbitrary test text through ONE channel (admin page). @return array{ok:bool,error:?string} */
    public function test(string $channel, string $to): array
    {
        $ch = $this->channels[$channel] ?? null;

        if (!$ch) {
            return ['ok' => false, 'error' => 'unknown-channel'];
        }

        if (!$ch->ready()) {
            return ['ok' => false, 'error' => 'not-configured'];
        }

        try {
            $addr = $channel === 'Email' || $channel === 'Telegram' ? $to : Http::phoneDigits($to, (string) $this->config->get('notifyCountryCode', '371'));
            [$subject, $body] = Texts::render('Test', Texts::lang(null, (string) $this->config->get('language', 'lv_LV')), ['brand' => $this->brand->docName()]);
            $ch->send($addr, $body, ['subject' => $subject, 'kind' => 'Test']);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 250)];
        }
    }

    private function record(
        Entity $order,
        ?Entity $customer,
        string $kind,
        string $channel,
        string $status,
        ?string $error,
        string $to,
        string $body
    ): Entity {
        $masked = $to;

        if (str_contains($to, '@')) {
            $masked = preg_replace('/^(.).*(@.*)$/', '$1***$2', $to) ?? '';
        } elseif (strlen($to) > 4) {
            $masked = str_repeat('*', strlen($to) - 4) . substr($to, -4);
        }

        return $this->em->createEntity('OrderMessage', [
            'name' => trim($kind . ' ' . ($channel ?: '—') . ' ' . date('Y-m-d H:i')),
            'kind' => $kind,
            'channel' => $channel,
            'status' => $status,
            'error' => $error,
            'recipient' => $masked,
            'body' => $body,
            'sentAt' => $status === 'Sent' ? date('Y-m-d H:i:s') : null,
            'customerId' => $customer ? $customer->getId() : null,
            'washOrderId' => $order->getId(),
        ]);
    }
}
