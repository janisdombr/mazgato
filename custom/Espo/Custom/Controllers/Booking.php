<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Acl;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Booking\CapacityReport;
use Espo\Custom\Tools\Booking\FormToken;
use Espo\Custom\Tools\Booking\Maintenance;
use Espo\Custom\Tools\Booking\Planner;
use Espo\Custom\Tools\Booking\RateLimiter;
use Espo\Custom\Tools\Booking\Turnstile;
use Espo\Custom\Tools\Brand\Brand;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Custom\Tools\Notify\Inbound;
use Espo\Custom\Tools\Notify\Notifier;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Booking/action/*
 *
 * PUBLIC (no login; routes.json noAuth): config, slots, create, manage, cancel, reschedule.
 * These run as the system user, so every one of them builds its answer field by field —
 * nothing is serialised from an entity, and no customer data is ever returned.
 * Guards on create: module flag, honeypot, signed + aged form token, per-IP rate limit,
 * input validation, hours/grid/lead/horizon, capacity under a DB lock (409 when taken).
 *
 * STAFF (login required): day (schedule), start, noShow, adminGet/adminSave/adminResetRate,
 * notifyTest, telegramLink.
 */
class Booking
{
    /** key => [type, secret?]  Everything the two Administration pages may write. */
    private const KEYS = [
        'notifyEmailOn' => ['bool'], 'notifyWhatsappOn' => ['bool'], 'notifySmsOn' => ['bool'], 'notifyTelegramOn' => ['bool'],
        'notifyChannelOrder' => ['str'], 'reviewUrl' => ['url'], 'notifyCountryCode' => ['str'],
        'whatsappPhoneNumberId' => ['str'], 'whatsappAccessToken' => ['str', true], 'whatsappNotifyTemplate' => ['str'],
        'whatsappTemplateLang' => ['str'], 'whatsappApiBase' => ['url'],
        'smsWebhookUrl' => ['url'], 'smsWebhookMethod' => ['str'], 'smsWebhookFormat' => ['str'], 'smsWebhookBody' => ['text'],
        'smsWebhookHeaders' => ['text', true], 'smsBasicAuth' => ['str', true], 'smsFrom' => ['str'],
        'telegramBotToken' => ['str', true], 'telegramBotUsername' => ['str'], 'telegramWebhookSecret' => ['str', true],
        'telegramApiBase' => ['url'], 'notifyInboundSecret' => ['str', true],
        'bookingHoursMon' => ['str'], 'bookingHoursTue' => ['str'], 'bookingHoursWed' => ['str'], 'bookingHoursThu' => ['str'],
        'bookingHoursFri' => ['str'], 'bookingHoursSat' => ['str'], 'bookingHoursSun' => ['str'],
        'bookingSlotMinutes' => ['int'], 'bookingPosts' => ['int'], 'bookingMaxCarsPerHour' => ['int'], 'bookingBufferMinutes' => ['int'],
        'bookingClosedDates' => ['text'], 'bookingMinLeadMinutes' => ['int'], 'bookingHorizonDays' => ['int'],
        'bookingReminderHours' => ['int'], 'bookingNoShowGraceMinutes' => ['int'], 'bookingRatePerHour' => ['int'],
        'bookingMinFillSeconds' => ['int'],
        'bookingWashers' => ['int'], 'bookingWashersByWeekday' => ['str'], 'bookingCrewDefault' => ['int'],
        'bookingDayCapacityPercent' => ['int'], 'bookingMaxCarsPerDay' => ['int'],
        'turnstileSiteKey' => ['str'], 'turnstileSecret' => ['str', true], 'turnstileHostname' => ['str'],
    ];

    public function __construct(
        private EntityManager $em,
        private User $user,
        private Config $config,
        private ConfigWriter $writer,
        private Log $log,
        private Planner $planner,
        private Notifier $notifier,
        private FormToken $token,
        private RateLimiter $limiter,
        private Modules $modules,
        private Brand $brand,
        private Maintenance $maintenance,
        private Inbound $inbound,
        private Acl $acl,
        private Turnstile $turnstile,
        private \Espo\Custom\Tools\Loyalty\Promo $promo,
        private \Espo\Custom\Tools\Loyalty\Referral $referral,
        private CapacityReport $report,
        DeviceGuard $deviceGuard
    ) {
        $deviceGuard->denyDevice($user);
    }

    // ------------------------------------------------------------------ helpers

    private function refuse(Response $r, int $status, string $code, array $extra = []): null
    {
        $r->setStatus($status);
        $r->setHeader('Content-Type', 'application/json');

        if ($status === 429) {
            $r->setHeader('Retry-After', '60');
        }

        $r->writeBody(json_encode(['ok' => false, 'error' => $code] + $extra, JSON_UNESCAPED_UNICODE));

        return null;
    }

    private function ip(Request $req): string
    {
        return (string) ($req->getHeader('X-Real-IP') ?: $req->getServerParam('REMOTE_ADDR') ?: '0');
    }

    private function str(?stdClass $d, string $k, int $max = 200): string
    {
        return mb_substr(trim((string) ($d->$k ?? '')), 0, $max);
    }

    /** Common gate for public endpoints; returns true when the caller may continue. */
    private function gate(Request $req, Response $res, string $bucket, int $limit, int $window): bool
    {
        if (!$this->modules->moduleEnabled(Modules::BOOKING)) {
            $this->refuse($res, 403, 'module_disabled');

            return false;
        }

        if (!$this->limiter->hit($bucket, $this->ip($req), $limit, $window)) {
            $this->refuse($res, 429, 'rate_limited');

            return false;
        }

        return true;
    }

    private function readLimit(): int { return 240; }

    private function createLimit(): int
    {
        $v = $this->config->get('bookingRatePerHour');

        return $v === null || $v === '' ? 10 : max(1, (int) $v);
    }

    private function utc(string $date, string $time): ?\DateTimeImmutable
    {
        $l = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $this->planner->tz());

        return $l ? $l->setTimezone(new \DateTimeZone('UTC')) : null;
    }

    /** @return array{0:?Entity,1:int,2:string[]} base service, minutes, add-on ids */
    private function resolveServices(stdClass $d): array
    {
        $svc = $this->em->getEntityById('WashService', $this->str($d, 'serviceId', 40));

        if (!$svc || !$svc->get('isActive') || $svc->get('category') !== 'Base') {
            return [null, 0, []];
        }

        $addons = [];

        foreach ((array) ($d->addonIds ?? []) as $id) {
            $a = $this->em->getEntityById('WashService', (string) $id);

            if ($a && $a->get('isActive') && $a->get('category') === 'Add-on') {
                $addons[] = $a->getId();
            }
        }

        return [$svc, $this->planner->duration($svc->getId(), $addons), array_values(array_unique($addons))];
    }

    // ------------------------------------------------------------------ public

    public function postActionConfig(Request $req, Response $res): ?stdClass
    {
        if (!$this->gate($req, $res, 'read', $this->readLimit(), 600)) {
            return null;
        }

        $services = [];

        foreach ($this->em->getRDBRepository('WashService')->where(['isActive' => true, 'deleted' => false])->order('sortOrder')->find() as $s) {
            $services[] = [
                'id' => $s->getId(),
                'name' => (string) $s->get('name'),
                'category' => (string) $s->get('category'),
                'price' => round((float) $s->get('priceRetail'), 2),
                'minutes' => (int) $s->get('durationMinutes'),
            ];
        }

        $color = $this->brand->color();
        $today = $this->planner->now()->setTimezone($this->planner->tz());

        $answer = (object) [
            'ok' => true,
            'brand' => ['name' => $this->brand->docName(), 'color' => $color, 'ink' => $color ? $this->brand->inkOn($color) : ''],
            'language' => (string) $this->config->get('language', 'lv_LV'),
            'services' => $services,
            'today' => $today->format('Y-m-d'),
            'horizonDays' => $this->planner->horizonDays(),
            'slotMinutes' => $this->planner->slotMinutes(),
            'token' => $this->token->issue(),
        ];

        // The PUBLIC site key only, and only while the secret is stored too (a widget nobody verifies would just annoy people).
        // The key is absent altogether when the check is off, so the answer is exactly what it was before.
        if ($key = $this->turnstile->widgetKey()) {
            $answer->turnstile = ['siteKey' => $key];
        }

        // Module loyalty: the form shows its optional "Promo code" field only while some code could work (a yes/no, no code is revealed).
        // Absent otherwise, so the answer is exactly what it was before.
        if ($this->modules->moduleEnabled(Modules::LOYALTY) && $this->promo->anyPublic()) {
            $answer->promo = true;
        }

        // The optional "Referral code" field: a boolean only (the programme is on). No customer, no code, no reward is revealed.
        if ($this->modules->moduleEnabled(Modules::LOYALTY) && $this->referral->enabled()) {
            $answer->referral = true;
        }

        return $answer;
    }

    public function postActionSlots(Request $req, Response $res): ?stdClass
    {
        if (!$this->gate($req, $res, 'read', $this->readLimit(), 600)) {
            return null;
        }

        $d = $req->getParsedBody();
        $date = $this->str($d, 'date', 10);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->planner->tz());
        [$svc, $minutes] = $this->resolveServices($d);
        $exclude = null;
        $mt = $this->str($d, 'manageToken', 40);

        if ($mt !== '') {
            // Rescheduling: the booking's own duration, and it must not collide with itself.
            $o = $this->em->getRDBRepository('WashOrder')->where(['manageToken' => $mt, 'deleted' => false])->findOne();
            $exclude = $o ? $o->getId() : null;

            if ($o) {
                $svc = $svc ?: $this->em->getEntityById('WashService', (string) $o->get('serviceId'));
                $minutes = $this->planner->orderDuration($o);
            }
        }

        if (!$day || $day->format('Y-m-d') !== $date || !$svc) {
            return $this->refuse($res, 400, 'bad_request');
        }

        $today = $this->planner->now()->setTimezone($this->planner->tz())->setTime(0, 0);
        $slots = [];

        if ($day >= $today && $day <= $today->modify('+' . $this->planner->horizonDays() . ' days')) {
            $slots = $this->planner->slots($date, $minutes, $exclude, ['crew' => $this->planner->crewFor($svc->getId()), 'online' => true]);
        }

        return (object) ['ok' => true, 'date' => $date, 'minutes' => $minutes, 'slots' => $slots];
    }

    public function postActionCreate(Request $req, Response $res): ?stdClass
    {
        if (!$this->gate($req, $res, 'create', $this->createLimit(), 3600)) {
            return null;
        }

        $d = $req->getParsedBody();

        // Honeypot: a human never sees this field. Answer like a success, store nothing.
        if ($this->str($d, 'website', 200) !== '') {
            return (object) ['ok' => true, 'orderNumber' => 'WEB-' . date('ymd-His'), 'manageToken' => bin2hex(random_bytes(12))];
        }

        $minAge = $this->config->get('bookingMinFillSeconds');
        $bad = $this->token->check($this->str($d, 'token', 200), $minAge === null || $minAge === '' ? 2 : (int) $minAge);

        if ($bad !== '') {
            return $this->refuse($res, 400, $bad);
        }

        [$svc, $minutes, $addons] = $this->resolveServices($d);
        $name = $this->str($d, 'name', 80);
        $phone = $this->str($d, 'phone', 30);
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $email = $this->str($d, 'email', 100);
        $plate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->str($d, 'plate', 20)) ?? '');
        $start = $this->utc($this->str($d, 'date', 10), $this->str($d, 'time', 5));

        $errors = [];
        $svc || $errors[] = 'service';
        mb_strlen($name) >= 2 || $errors[] = 'name';
        (strlen($digits) >= 8 && strlen($digits) <= 15) || $errors[] = 'phone';
        ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) || $errors[] = 'email';
        preg_match('/^[A-Z0-9]{2,10}$/', $plate) || $errors[] = 'plate';
        $start || $errors[] = 'time';

        if ($errors) {
            return $this->refuse($res, 400, 'validation', ['fields' => $errors]);
        }

        // Optional promo code. The visitor learns "ok" or "invalid" - never why (unknown, off, expired and used-up codes look the
        // same) - and failures are counted per address on top of the form's own limit, so the form cannot be used to guess codes.
        // Only code-level checks run here; the customer's own limits and the discount itself are decided when the car arrives
        // (Hooks/WashOrder/ApplyLoyalty), and nothing about an existing customer is ever looked at or said.
        $promoCode = \Espo\Custom\Tools\Loyalty\Promo::normalize($this->str($d, 'promo', 40));
        // The same for the optional referral code: ok / invalid only (an unknown code, a switched-off programme look alike), and a wrong
        // one is counted in the SAME per-address bucket as a wrong promo code. Whether it can be given to THIS customer (self, a customer who
        // already washed, a cap ...) is decided when the car arrives and never said here: a booking says nothing about an existing customer.
        $referralCode = \Espo\Custom\Tools\Loyalty\Referral::normalize($this->str($d, 'referral', 20));

        if ($promoCode !== '' || $referralCode !== '') {
            $ip = $this->ip($req);

            if ($this->limiter->exceeded('promo-bad', $ip, 5, 3600)) {
                return $this->refuse($res, 429, 'rate_limited');
            }

            $bad = [];
            $on = $this->modules->moduleEnabled(Modules::LOYALTY);

            if ($promoCode !== '' && (!$on || !$this->promo->publicOk($promoCode))) {
                $bad[] = 'promo';
            }

            if ($referralCode !== '' && (!$on || !$this->referral->publicOk($referralCode))) {
                $bad[] = 'referral';
            }

            if ($bad) {
                $this->limiter->hit('promo-bad', $ip, 5, 3600);

                return $this->refuse($res, 400, 'validation', ['fields' => $bad]);
            }
        }

        // Cloudflare Turnstile (only when a secret is stored): after the cheap local checks so a typo does not burn the
        // one-time token, before anything touches the schedule. CLOSED: Cloudflare unreachable = refused, no detail.
        // The customer chat replays the booking from inside the server (ForwardedRequest): it has no widget to solve, and it carries its own
        // gates (form token, per-address limits, bookings per address per day, honeypot), so the bot check is not asked of it.
        if ($this->turnstile->enabled() && !($req instanceof \Espo\Custom\Tools\ClientAssistant\ForwardedRequest)) {
            $ts = $this->turnstile->verify($this->str($d, 'turnstileToken', 2100), $this->ip($req));

            if ($ts === Turnstile::FAILED) {
                return $this->refuse($res, 403, 'captcha_failed');
            }

            if ($ts !== Turnstile::OK) {
                return $this->refuse($res, 503, 'captcha_unavailable');
            }
        }

        $now = $this->planner->now();

        if ($start < $now->modify('+' . $this->planner->lead() . ' minutes')
            || $start > $now->modify('+' . $this->planner->horizonDays() . ' days')
            || !$this->planner->onGrid($start, $minutes)
        ) {
            return $this->refuse($res, 422, 'slot_unavailable');
        }

        $pdo = $this->em->getPDO();
        $pdo->query("SELECT GET_LOCK('carwash_booking', 5)");

        try {
            $chk = $this->planner->check($start, $minutes, null, true, null, ['crew' => $this->planner->crewFor($svc->getId()), 'online' => true]);

            if (!$chk['ok']) {
                return $this->refuse($res, 409, $chk['reason'] === 'day_full' ? 'day_full' : 'slot_taken');
            }

            $isNewCustomer = false;
            $vehicle = $this->em->getRDBRepository('Vehicle')->where(['name' => $plate, 'deleted' => false])->findOne();
            $customer = $vehicle && $vehicle->get('customerId') ? $this->em->getEntityById('Customer', $vehicle->get('customerId')) : null;

            if (!$customer) {
                $customer = $this->findByPhone($digits);
            }

            if (!$customer) {
                // Existing customers are never modified from this public form.
                $isNewCustomer = true;
                $lang = $this->str($d, 'lang', 5);
                $customer = $this->em->createEntity('Customer', [
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email !== '' ? $email : null,
                    'language' => in_array($lang, ['lv_LV', 'ru_RU', 'en_US', 'et_EE', 'lt_LT'], true) ? $lang : '',
                ]);
            }

            if (!$vehicle) {
                $vehicle = $this->em->createEntity('Vehicle', ['name' => $plate, 'customerId' => $customer->getId()]);
            }

            try {
                $order = $this->em->createEntity('WashOrder', [
                    'name' => 'WEB-' . date('ymd-His') . '-' . random_int(10, 99),
                    'status' => 'Reserved',
                    'source' => 'Website',
                    'orderDateTime' => $start->format('Y-m-d H:i:s'),
                    'customerId' => $customer->getId(),
                    'vehicleId' => $vehicle->getId(),
                    'serviceId' => $svc->getId(),
                    'addonsIds' => $addons,
                    'plannedMinutes' => $minutes,
                    'bookingEmail' => $email !== '' ? $email : null,
                    'reservationNote' => $this->str($d, 'note', 300) ?: null,
                    'isCorporate' => (bool) $customer->get('isCorporate'),
                    'promoCode' => $promoCode !== '' ? $promoCode : null,
                    'referralCode' => $referralCode !== '' ? $referralCode : null,
                ], ['bookingOnline' => true]);
            } catch (Conflict $e) {
                return $this->refuse($res, 409, str_starts_with($e->getMessage(), 'day_full') ? 'day_full' : 'slot_taken');
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('carwash_booking')");
        }

        $msg = $this->notifier->notify($order, 'Booked');
        $out = [
            'ok' => true,
            'orderNumber' => (string) $order->get('name'),
            'date' => $this->str($d, 'date', 10),
            'time' => $this->str($d, 'time', 5),
            'manageToken' => (string) $order->get('manageToken'),
            'notified' => $msg ? (string) $msg->get('status') : 'none',
        ];

        $bot = trim((string) $this->config->get('telegramBotUsername', ''));

        if ($isNewCustomer && $bot !== '' && $this->config->get('notifyTelegramOn')) {
            $out['telegramLink'] = $this->telegramLinkFor($customer, $bot);
        }

        return (object) $out;
    }

    private function findByPhone(string $digits): ?Entity
    {
        $last = substr($digits, -8);

        // Stored numbers are formatted by hand ("+371 2111 2345"), so narrow by the tail only
        // and compare the digits in PHP.
        foreach ($this->em->getRDBRepository('Customer')->where(['phone*' => '%' . substr($last, -4), 'deleted' => false])->limit(0, 200)->find() as $c) {
            if (substr(preg_replace('/\D+/', '', (string) $c->get('phone')) ?? '', -8) === $last) {
                return $c;
            }
        }

        return null;
    }

    private function telegramLinkFor(Entity $c, string $bot): string
    {
        $t = (string) $c->get('telegramLinkToken');

        if ($t === '') {
            $t = bin2hex(random_bytes(10));
            $c->set('telegramLinkToken', $t);
            $this->em->saveEntity($c, ['skipHooks' => true]);
        }

        return 'https://t.me/' . $bot . '?start=' . $t;
    }

    private function byToken(stdClass $d): ?Entity
    {
        $mt = $this->str($d, 'manageToken', 40) ?: $this->str($d, 'mt', 40);

        return $mt === '' ? null : $this->em->getRDBRepository('WashOrder')->where(['manageToken' => $mt, 'deleted' => false])->findOne();
    }

    private function summary(Entity $o): array
    {
        $v = $o->get('vehicleId') ? $this->em->getEntityById('Vehicle', $o->get('vehicleId')) : null;
        $s = $o->get('serviceId') ? $this->em->getEntityById('WashService', $o->get('serviceId')) : null;
        $l = (new \DateTimeImmutable((string) $o->get('orderDateTime'), new \DateTimeZone('UTC')))->setTimezone($this->planner->tz());

        return [
            'orderNumber' => (string) $o->get('name'),
            'status' => (string) $o->get('status'),
            'date' => $l->format('Y-m-d'),
            'time' => $l->format('H:i'),
            'plate' => $v ? (string) $v->get('name') : '',
            'service' => $s ? (string) $s->get('name') : '',
            'minutes' => (int) $o->get('plannedMinutes'),
            'serviceId' => (string) $o->get('serviceId'),
            'canChange' => $o->get('status') === 'Reserved' && $l->setTimezone(new \DateTimeZone('UTC')) > $this->planner->now(),
        ];
    }

    public function postActionManage(Request $req, Response $res): ?stdClass
    {
        if (!$this->gate($req, $res, 'read', $this->readLimit(), 600)) {
            return null;
        }

        $o = $this->byToken($req->getParsedBody());

        return $o ? (object) (['ok' => true] + $this->summary($o)) : $this->refuse($res, 404, 'not_found');
    }

    public function postActionCancel(Request $req, Response $res): ?stdClass
    {
        if (!$this->gate($req, $res, 'create', $this->createLimit() * 3, 3600)) {
            return null;
        }

        $o = $this->byToken($req->getParsedBody());

        if (!$o) {
            return $this->refuse($res, 404, 'not_found');
        }

        if ($o->get('status') === 'Cancelled') {
            return (object) (['ok' => true, 'already' => true] + $this->summary($o));
        }

        if ($o->get('status') !== 'Reserved') {
            return $this->refuse($res, 409, 'not_cancellable');
        }

        $o->set('status', 'Cancelled');
        $this->em->saveEntity($o, ['skipReserveGuard' => true]);
        $this->notifier->notify($o, 'Cancelled');

        return (object) (['ok' => true] + $this->summary($o));
    }

    public function postActionReschedule(Request $req, Response $res): ?stdClass
    {
        if (!$this->gate($req, $res, 'create', $this->createLimit() * 3, 3600)) {
            return null;
        }

        $d = $req->getParsedBody();
        $o = $this->byToken($d);

        if (!$o) {
            return $this->refuse($res, 404, 'not_found');
        }

        $start = $this->utc($this->str($d, 'date', 10), $this->str($d, 'time', 5));
        $minutes = $this->planner->orderDuration($o);
        $now = $this->planner->now();

        if ($o->get('status') !== 'Reserved') {
            return $this->refuse($res, 409, 'not_changeable');
        }

        if (!$start || $start < $now->modify('+' . $this->planner->lead() . ' minutes')
            || $start > $now->modify('+' . $this->planner->horizonDays() . ' days')
            || !$this->planner->onGrid($start, $minutes)
        ) {
            return $this->refuse($res, 422, 'slot_unavailable');
        }

        $pdo = $this->em->getPDO();
        $pdo->query("SELECT GET_LOCK('carwash_booking', 5)");

        try {
            $o->set(['orderDateTime' => $start->format('Y-m-d H:i:s'), 'reminderSentAt' => null]);
            $this->em->saveEntity($o, ['bookingOnline' => true]);
        } catch (Conflict $e) {
            return $this->refuse($res, 409, str_starts_with($e->getMessage(), 'day_full') ? 'day_full' : 'slot_taken');
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('carwash_booking')");
        }

        return (object) (['ok' => true] + $this->summary($o));
    }

    /** Public opt-out from service messages; the per-customer token is the authorisation. */
    public function postActionOptOut(Request $req, Response $res): ?stdClass
    {
        if (!$this->limiter->hit('optout', $this->ip($req), 30, 3600)) {
            return $this->refuse($res, 429, 'rate_limited');
        }

        $token = $this->str($req->getParsedBody(), 'token', 40);
        $c = $token === '' ? null : $this->em->getRDBRepository('Customer')->where(['unsubscribeToken' => $token, 'deleted' => false])->findOne();

        if (!$c) {
            return $this->refuse($res, 404, 'not_found');
        }

        if ($c->get('allowServiceMessages') !== false) {
            $c->set(['allowServiceMessages' => false, 'serviceMessagesOffAt' => date('Y-m-d H:i:s')]);
            $this->em->saveEntity($c, ['skipHooks' => true]);
        }

        return (object) ['ok' => true];
    }

    /** Webhook for replies (Telegram updates, SMS / WhatsApp STOP). Secret-gated. */
    public function postActionInbound(Request $req, Response $res): ?stdClass
    {
        $body = json_decode((string) $req->getBodyContents(), true);
        $code = $this->inbound->handle(
            (string) $req->getRouteParam('channel'),
            is_array($body) ? $body : [],
            (string) $req->getHeader('X-Telegram-Bot-Api-Secret-Token'),
            (string) ($req->getQueryParam('secret') ?? '')
        );

        return $code === 200 ? (object) ['ok' => true] : $this->refuse($res, $code, 'forbidden');
    }

    // ------------------------------------------------------------------ staff

    /** Day schedule for the dashlet. */
    public function postActionDay(Request $req): stdClass
    {
        // The schedule shows customer names and plates of every order of the day: the same right as the order list.
        if (!$this->acl->checkScope('WashOrder', 'read')) {
            throw new Forbidden();
        }

        $d = $req->getParsedBody();
        $tz = $this->planner->tz();
        $date = $this->str($d, 'date', 10) ?: $this->planner->now()->setTimezone($tz)->format('Y-m-d');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz) ?: throw new \Espo\Core\Exceptions\BadRequest('date');
        $from = $day->setTimezone(new \DateTimeZone('UTC'));
        $to = $day->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));
        $rows = [];

        $orders = $this->em->getRDBRepository('WashOrder')->where([
            'deleted' => false,
            'orderDateTime>=' => $from->format('Y-m-d H:i:s'),
            'orderDateTime<' => $to->format('Y-m-d H:i:s'),
            'status!=' => 'Cancelled',
        ])->order('orderDateTime')->find();

        foreach ($orders as $o) {
            $s = (new \DateTimeImmutable((string) $o->get('orderDateTime'), new \DateTimeZone('UTC')))->setTimezone($tz);
            $m = $this->planner->orderDuration($o);
            $v = $o->get('vehicleId') ? $this->em->getEntityById('Vehicle', $o->get('vehicleId')) : null;
            $svc = $o->get('serviceId') ? $this->em->getEntityById('WashService', $o->get('serviceId')) : null;
            $rows[] = [
                'id' => $o->getId(), 'name' => (string) $o->get('name'), 'status' => (string) $o->get('status'),
                'plate' => $v ? (string) $v->get('name') : '', 'service' => $svc ? (string) $svc->get('name') : '',
                'post' => $o->get('post') ? (int) $o->get('post') : null,
                'startMin' => (int) $s->format('G') * 60 + (int) $s->format('i'), 'minutes' => $m,
                'time' => $s->format('H:i'), 'customer' => (string) $o->get('customerName'),
            ];
        }

        $h = $this->planner->hoursFor($day);

        return (object) [
            'date' => $date, 'posts' => $this->planner->posts(), 'slotMinutes' => $this->planner->slotMinutes(),
            'open' => $h ? $h[0] : null, 'close' => $h ? $h[1] : null, 'rows' => $rows,
        ];
    }

    public function postActionStart(Request $req): stdClass
    {
        $o = $this->em->getEntityById('WashOrder', $this->str($req->getParsedBody(), 'id', 40));

        if (!$o) {
            throw new \Espo\Core\Exceptions\NotFound();
        }

        if (!$this->acl->checkEntity($o, 'edit')) {
            throw new Forbidden();
        }

        if ($o->get('status') !== 'Reserved') {
            throw new Conflict('Only a reservation can be started.');
        }

        $o->set('status', 'In Progress');
        $this->em->saveEntity($o, ['skipReserveGuard' => true]);

        return (object) ['ok' => true, 'status' => $o->get('status')];
    }

    public function postActionNoShow(Request $req): stdClass
    {
        $o = $this->em->getEntityById('WashOrder', $this->str($req->getParsedBody(), 'id', 40));

        if (!$o) {
            throw new \Espo\Core\Exceptions\NotFound();
        }

        if (!$this->acl->checkEntity($o, 'edit')) {
            throw new Forbidden();
        }

        if ($o->get('status') !== 'Reserved') {
            throw new Conflict('Only a reservation can be marked as no-show.');
        }

        $this->maintenance->markNoShow($o);

        return (object) ['ok' => true];
    }

    /**
     * The order calendar: per day (and, for up to 8 days, per hour) the capacity, what is booked, what is free, and the orders.
     * Staff only: the right to read every order (level all), because it shows plates and customer names of the whole shop.
     */
    public function postActionRange(Request $req, Response $res): ?stdClass
    {
        if (!$this->modules->moduleEnabled(Modules::BOOKING)) {
            return $this->refuse($res, 403, 'module_disabled');
        }

        if (!$this->acl->checkScope('WashOrder', 'read') || $this->acl->getLevel('WashOrder', 'read') !== 'all') {
            throw new Forbidden();
        }

        $d = $req->getParsedBody();

        return (object) (['ok' => true] + $this->report->range($this->str($d, 'from', 10), $this->str($d, 'to', 10), !empty($d->summary)));
    }

    /** The capacity calculator on the booking settings page: the same Planner, fed with the (unsaved) form values. Administrator only. */
    public function postActionCapacityPreview(Request $req): stdClass
    {
        $this->admin();
        $values = [];

        foreach ((array) ($req->getParsedBody()->values ?? []) as $k => $v) {
            if (isset(self::KEYS[$k]) && str_starts_with((string) $k, 'booking')) {
                $values[$k] = self::KEYS[$k][0] === 'int' ? ($v === '' || $v === null ? null : max(0, (int) $v)) : (is_string($v) ? mb_substr($v, 0, 2000) : $v);
            }
        }

        return (object) (['ok' => true] + $this->report->preview($values));
    }

    private function admin(): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }
    }

    public function postActionAdminGet(): stdClass
    {
        $this->admin();
        $values = [];
        $secrets = [];

        foreach (self::KEYS as $k => $def) {
            if (!empty($def[1])) {
                $secrets[$k] = trim((string) $this->config->get($k, '')) !== '';
            } else {
                $values[$k] = $this->config->get($k, $k === 'notifyEmailOn' ? true : null);
            }
        }

        return (object) [
            'values' => (object) $values,
            'secrets' => (object) $secrets,
            'smtp' => (bool) $this->config->get('smtpServer'),
            'modules' => [
                'notify' => $this->modules->moduleEnabled(Modules::NOTIFY),
                'booking' => $this->modules->moduleEnabled(Modules::BOOKING),
            ],
            'siteUrl' => (string) $this->config->get('siteUrl', ''),
            'channels' => array_map(fn($c) => $c->ready(), $this->notifier->channels()),
        ];
    }

    public function postActionAdminSave(Request $req): stdClass
    {
        $this->admin();
        $d = $req->getParsedBody();
        $set = [];

        foreach ((array) ($d->values ?? []) as $k => $v) {
            $def = self::KEYS[$k] ?? null;

            if (!$def) {
                continue;
            }

            if (!empty($def[1]) && ($v === '' || $v === null)) {
                continue; // a blank secret field means "keep the current one"
            }

            $set[$k] = match ($def[0]) {
                'bool' => (bool) $v,
                'int' => $v === '' || $v === null ? null : max(0, (int) $v),
                'url' => $this->url((string) $v),
                'text' => mb_substr((string) $v, 0, 2000),
                default => mb_substr(trim((string) $v), 0, 300),
            };
        }

        foreach ((array) ($d->clear ?? []) as $k) {
            if (!empty(self::KEYS[$k][1])) {
                $set[$k] = '';
            }
        }

        if ($set) {
            $this->writer->setMultiple($set);
            $this->writer->save();
        }

        return (object) ['ok' => true, 'saved' => array_keys($set)];
    }

    private function url(string $v): string
    {
        $v = trim($v);

        if ($v !== '' && !preg_match('#^https?://[^\s]+$#i', $v)) {
            throw new \Espo\Core\Exceptions\BadRequest('URL must start with http:// or https://');
        }

        return $v;
    }

    public function postActionAdminMaintenance(Request $req): stdClass
    {
        $this->admin();
        $only = $this->str($req->getParsedBody(), 'orderId', 40) ?: null;

        return (object) ($this->maintenance->run($only) + ['ok' => true]);
    }

    public function postActionAdminResetRate(): stdClass
    {
        $this->admin();
        $this->limiter->reset();

        return (object) ['ok' => true];
    }

    public function postActionNotifyTest(Request $req): stdClass
    {
        $this->admin();
        $d = $req->getParsedBody();
        $r = $this->notifier->test($this->str($d, 'channel', 20), $this->str($d, 'to', 120));

        return (object) $r;
    }

    public function postActionTelegramLink(Request $req): stdClass
    {
        $c = $this->em->getEntityById('Customer', $this->str($req->getParsedBody(), 'customerId', 40));
        $bot = trim((string) $this->config->get('telegramBotUsername', ''));

        if ($c && !$this->acl->checkEntity($c, 'read')) {
            throw new Forbidden();
        }

        if (!$c || $bot === '') {
            throw new \Espo\Core\Exceptions\NotFound();
        }

        return (object) ['link' => $this->telegramLinkFor($c, $bot)];
    }
}
