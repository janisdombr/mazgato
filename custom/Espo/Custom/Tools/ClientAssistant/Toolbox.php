<?php

namespace Espo\Custom\Tools\ClientAssistant;

use Espo\Core\InjectableFactory;
use Espo\Custom\Controllers\Booking;
use Espo\Custom\Tools\Assistant\Untrusted;
use Espo\Custom\Tools\Booking\Planner;
use Espo\ORM\EntityManager;

/**
 * The public chat's ENTIRE tool list - two tools, nothing else exists:
 *
 *   free_slots      reads the booking planner (the same list the booking page shows): times only,
 *                   no names, no plates, no counts of other people's bookings.
 *   create_booking  forwards to Booking::postActionCreate - the code behind the public booking
 *                   form - so the token check, per-IP limit, hours/grid/lead/horizon,
 *                   the MySQL lock and ReserveGuard all run exactly as for a form post. Nothing
 *                   is written here; there is no other write path.
 *
 * There is deliberately no tool that reads a customer, an order, revenue, staff or payroll.
 */
class Toolbox
{
    public const NAMES = ['free_slots', 'create_booking'];

    /** @var array<string, mixed>|null the booking made in this turn (for the confirmation card) */
    public ?array $booking = null;

    public function __construct(
        private EntityManager $em,
        private Planner $planner,
        private InjectableFactory $factory,
        private ClientConfig $cfg,
        private ClientJournal $journal,
        private ContactVault $vault,
        private string $ip,
        private string $ipHash,
        private string $token,
        private string $lang,
        private string $typed,
        private bool $hasHistory
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function specs(): array
    {
        return [
            [
                'name' => 'free_slots',
                'description' => 'Free start times for a wash on a given day (and up to 2 following days), exactly as the online booking page offers them. Needs the base service id from the price list. Times are local, 24h.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'serviceId' => ['type' => 'string', 'description' => 'id of a Base service from the price list'],
                    'addonIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'ids of Add-on services (they lengthen the wash)'],
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, local. Default: today.'],
                    'days' => ['type' => 'integer', 'description' => '1 to 3 consecutive days starting at date. Default 1.'],
                ], 'required' => ['serviceId'], 'additionalProperties' => false],
            ],
            [
                'name' => 'create_booking',
                'description' => 'Book a wash. Call ONLY after the customer has said yes to your written summary, and only with a time that free_slots returned. Name, phone, plate and e-mail: pass the placeholder exactly as shown in the conversation, e.g. [NAME-1], [PHONE-1], [PLATE-1], [EMAIL-1]; never write a real name, number or plate yourself.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'confirmed' => ['type' => 'boolean', 'description' => 'true only if the customer explicitly confirmed the summary'],
                    'serviceId' => ['type' => 'string'],
                    'addonIds' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'time' => ['type' => 'string', 'description' => 'HH:MM, a time from free_slots'],
                    'name' => ['type' => 'string', 'description' => 'the placeholder, e.g. [NAME-1]'],
                    'phone' => ['type' => 'string', 'description' => 'the placeholder, e.g. [PHONE-1]'],
                    'email' => ['type' => 'string', 'description' => 'optional, the placeholder e.g. [EMAIL-1]'],
                    'plate' => ['type' => 'string', 'description' => 'the placeholder, e.g. [PLATE-1]'],
                    'note' => ['type' => 'string', 'description' => 'optional wish of the customer, up to 200 characters'],
                    'promo' => ['type' => 'string', 'description' => 'optional promo code exactly as the customer wrote it; leave it out if they gave none. Never invent one.'],
                    'referral' => ['type' => 'string', 'description' => 'optional referral code of a friend who recommended the wash (letters and digits), exactly as the customer wrote it; leave it out if they gave none. Never invent one.'],
                ], 'required' => ['confirmed', 'serviceId', 'date', 'time', 'name', 'phone', 'plate'], 'additionalProperties' => false],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function run(string $name, array $args): array
    {
        try {
            return match ($name) {
                'free_slots' => $this->freeSlots($args),
                'create_booking' => $this->book($args),
                default => $this->fail(404, 'unknown tool'),
            };
        } catch (\Throwable $e) {
            return $this->fail(500, 'The tool failed. Tell the customer you cannot check that right now and suggest phoning.');
        }
    }

    /** @return array<string, mixed> */
    private function fail(int $status, string $detail): array
    {
        return ['error' => ['status' => $status, 'detail' => $detail]];
    }

    private function s(mixed $v, int $max): string
    {
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }

    /** @return array{0: ?\Espo\ORM\Entity, 1: int, 2: string[]} */
    private function services(array $a): array
    {
        $svc = $this->em->getEntityById('WashService', $this->s($a['serviceId'] ?? '', 40));

        if (!$svc || !$svc->get('isActive') || $svc->get('category') !== 'Base') {
            return [null, 0, []];
        }

        $addons = [];

        foreach (array_slice((array) ($a['addonIds'] ?? []), 0, 10) as $id) {
            $x = $this->em->getEntityById('WashService', $this->s($id, 40));

            if ($x && $x->get('isActive') && $x->get('category') === 'Add-on') {
                $addons[] = $x->getId();
            }
        }

        $addons = array_values(array_unique($addons));

        return [$svc, $this->planner->duration($svc->getId(), $addons), $addons];
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function freeSlots(array $a): array
    {
        [$svc, $minutes] = $this->services($a);

        if (!$svc) {
            return $this->fail(400, 'serviceId must be the id of an active Base service from the price list.');
        }

        $tz = $this->planner->tz();
        $today = $this->planner->now()->setTimezone($tz)->setTime(0, 0);
        $date = $this->s($a['date'] ?? '', 10);
        $first = $date === '' ? $today : \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);

        if (!$first || ($date !== '' && $first->format('Y-m-d') !== $date)) {
            return $this->fail(400, 'date must be YYYY-MM-DD.');
        }

        $days = is_numeric($a['days'] ?? null) ? max(1, min(3, (int) $a['days'])) : 1;
        $out = [];

        for ($i = 0; $i < $days; $i++) {
            $d = $first->modify('+' . $i . ' days');
            $row = ['date' => $d->format('Y-m-d')];

            if ($d < $today || $d > $today->modify('+' . $this->planner->horizonDays() . ' days')) {
                $row += ['bookable' => false, 'reason' => 'outside the online booking window'];
            } elseif (!($h = $this->planner->hoursFor($d))) {
                $row += ['bookable' => false, 'reason' => 'closed that day'];
            } else {
                $times = array_map(fn($s) => (string) $s['time'], $this->planner->slots($d->format('Y-m-d'), $minutes, null, ['crew' => $this->planner->crewFor($svc->getId()), 'online' => true]));
                $row += [
                    'bookable' => true,
                    'open' => sprintf('%02d:%02d', intdiv($h[0], 60), $h[0] % 60),
                    'close' => sprintf('%02d:%02d', intdiv($h[1], 60), $h[1] % 60),
                    'freeStarts' => count($times),
                    'times' => array_slice($times, 0, 40),
                ];

                if (count($times) > 40) {
                    $row['more'] = 'only the first 40 start times are listed';
                }
            }

            $out[] = $row;
        }

        return [
            'source' => 'Booking planner (the online booking page)',
            'service' => Untrusted::clean((string) $svc->get('name'), 60),
            'washMinutes' => $minutes,
            'minLeadMinutes' => $this->planner->lead(),
            'localTimeNow' => $this->planner->now()->setTimezone($tz)->format('Y-m-d H:i'),
            'days' => $out,
        ];
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function book(array $a): array
    {
        if (($a['confirmed'] ?? false) !== true) {
            return $this->fail(400, 'confirmed must be true, and only after the customer said yes to your written summary.');
        }

        if (!$this->hasHistory) {
            return $this->fail(409, 'Write the summary of the booking first and wait for the customer to confirm it in their NEXT message.');
        }

        $max = $this->cfg->bookingsPerIpDay();

        if ($max < 1 || $this->journal->bookingsTodayFor($this->ipHash) >= $max) {
            return $this->fail(429, 'The chat cannot make more bookings for this visitor today. Ask them to phone or use the online booking form.');
        }

        [$svc, , $addons] = $this->services($a);

        if (!$svc) {
            return $this->fail(400, 'serviceId must be the id of an active Base service from the price list.');
        }

        $phone = $this->vault->resolve($this->s($a['phone'] ?? '', 40), $this->typed, 'PHONE');

        if ($phone === null) {
            return $this->fail(400, 'phone: pass the placeholder exactly as it appears in the conversation (like [PHONE-1]). If the customer has not given a number yet, end your message with [[DETAILS]] so that the form is shown.');
        }

        $name = $this->vault->resolve($this->s($a['name'] ?? '', 80), $this->typed, 'NAME');

        if ($name === null) {
            return $this->fail(400, 'name: pass the placeholder exactly as it appears in the conversation (like [NAME-1]). If the customer has not given a name yet, end your message with [[DETAILS]] so that the form is shown.');
        }

        $plate = $this->vault->resolve($this->s($a['plate'] ?? '', 20), $this->typed, 'PLATE');

        if ($plate === null) {
            return $this->fail(400, 'plate: pass the placeholder exactly as it appears in the conversation (like [PLATE-1]). If the customer has not given a plate yet, end your message with [[DETAILS]] so that the form is shown.');
        }

        $emailArg = $this->s($a['email'] ?? '', 100);
        $email = $emailArg === '' ? '' : $this->vault->resolve($emailArg, $this->typed, 'EMAIL');

        if ($email === null) {
            return $this->fail(400, 'email: pass the placeholder (like [EMAIL-1]) or leave it out.');
        }

        $lang = ['lv' => 'lv_LV', 'ru' => 'ru_RU', 'en' => 'en_US', 'et' => 'et_EE', 'lt' => 'lt_LT'][$this->lang] ?? '';
        $note = $this->s($a['note'] ?? '', 200);
        $body = [
            'token' => $this->token,
            'website' => '',
            'serviceId' => $svc->getId(),
            'addonIds' => $addons,
            'date' => $this->s($a['date'] ?? '', 10),
            'time' => $this->s($a['time'] ?? '', 5),
            'name' => Untrusted::clean($name, 80),
            'phone' => $phone,
            'email' => $email,
            'plate' => $plate,
            'note' => '[chat] ' . Untrusted::clean($note, 200),
            'lang' => $lang,
            // forwarded as it is: the booking code answers "ok" or "invalid" and counts failures per address
            'promo' => $this->s($a['promo'] ?? '', 24),
            'referral' => $this->s($a['referral'] ?? '', 12),
        ];

        // The ONE write path: the public booking form's own code, with the visitor's own address.
        $cap = new CaptureResponse();
        $res = $this->factory->create(Booking::class)->postActionCreate(new ForwardedRequest($body, $this->ip), $cap);
        $r = $res ? (json_decode((string) json_encode($res), true) ?: []) : $cap->json();

        if (empty($r['ok'])) {
            return $this->failure($cap->getStatusCode(), (string) ($r['error'] ?? ''), (array) ($r['fields'] ?? []));
        }

        $mt = (string) ($r['manageToken'] ?? '');
        $url = $this->cfg->siteUrl() !== '' && preg_match('/^[a-f0-9]{8,64}$/', $mt) ? $this->cfg->siteUrl() . '/booking/?m=' . $mt : '';

        $this->booking = [
            'orderNumber' => (string) ($r['orderNumber'] ?? ''),
            'date' => (string) ($r['date'] ?? $body['date']),
            'time' => (string) ($r['time'] ?? $body['time']),
            'service' => Untrusted::clean((string) $svc->get('name'), 60),
            'manageUrl' => $url,
        ];

        return [
            'ok' => true,
            'orderNumber' => $this->booking['orderNumber'],
            'date' => $this->booking['date'],
            'time' => $this->booking['time'],
            'service' => $this->booking['service'],
            'note' => 'The booking is made. The customer now sees a confirmation card with the link to change or cancel it; do not write a link.',
        ];
    }

    /** @param string[] $fields @return array<string, mixed> */
    private function failure(int $status, string $error, array $fields): array
    {
        $fields = array_values(array_filter(array_map(fn($f) => preg_replace('/[^a-z]/', '', (string) $f), $fields)));
        error_log('ClientAssistant: booking refused, HTTP ' . $status . ', ' . preg_replace('/[^A-Za-z0-9_.\-]/', '', mb_substr($error, 0, 40)) . ($fields ? ', fields ' . implode(',', $fields) : ''));

        return match (true) {
            $error === 'slot_taken' || $error === 'slot_unavailable' => $this->fail($status ?: 409, 'That time is not available (taken, outside hours or too soon). Call free_slots again and offer other times.'),
            $error === 'day_full' => $this->fail($status ?: 409, 'That day is fully booked online. Call free_slots for other days and offer one of them, or suggest phoning.'),
            $error === 'validation' && in_array('referral', $fields, true) => $this->fail(400, 'The referral code is not valid. Tell the customer it is not valid (do not say why, you do not know) and offer to book without it or with another code.'),
            $error === 'validation' && in_array('promo', $fields, true) => $this->fail(400, 'The promo code is not valid. Tell the customer it is not valid (do not say why, you do not know) and offer to book without it or with another code.'),
            $error === 'validation' => $this->fail(400, 'Missing or invalid: ' . ($fields ? implode(', ', $fields) : 'some fields') . '. Ask the customer for it (plate: letters and digits only; phone: at least 8 digits).'),
            $status === 429 || $error === 'rate_limited' => $this->fail(429, 'Too many booking requests from this connection. Ask the customer to phone or try again later.'),
            in_array($error, ['bad-token', 'too-fast', 'expired'], true) => $this->fail(400, 'The chat session is not valid any more. Ask the customer to reload the page and try again.'),
            $error === 'module_disabled' => $this->fail(403, 'Online booking is switched off. Ask the customer to phone.'),
            default => $this->fail($status ?: 500, 'The booking could not be made. Suggest phoning.'),
        };
    }
}
