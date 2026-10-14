<?php

namespace Espo\Custom\Tools\Accounting;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Turns one COMPLETED WashOrder into a neutral sale document that every connector maps to its own program.
 *
 * Rules (the same single ledger as the sales journal and the receipt):
 *   - only `Completed` orders; anything else is `['skip' => 'not_completed']`;
 *   - the money comes from the order's OrderAddon lines (base wash + add-ons) - ONE truth about the price; their gross sum
 *     is the order's `finalPrice`. A total of 0 (100 % discount, a wash paid from a subscription: its lines are 0 because the
 *     money was counted at the sale) has nothing to book: `['skip' => 'zero_total']`;
 *   - net and VAT are the order's own `amountNet` / `vatAmount` (21 % PVN, computed whether or not the receipt shows the
 *     split), spread over the lines by LineSplitter (last line takes the cents);
 *   - a private person is NEVER sent by name: they become the one general customer (setting `accountingRetailName`, default
 *     "Roku mazgāšana"); a company with a registration number is sent by reg. no. / PVN no. (business data).
 */
class SaleBuilder
{
    public function __construct(private EntityManager $em, private Cfg $cfg)
    {}

    /**
     * @return array<string, mixed> a sale document, or ['skip' => reason]
     */
    public function build(Entity $o): array
    {
        if ($o->get('status') !== 'Completed') {
            return ['skip' => 'not_completed'];
        }

        $gross = round((float) $o->get('finalPrice'), 2);

        if ($gross <= 0.0) {
            return ['skip' => 'zero_total'];
        }

        $vat = round((float) $o->get('vatAmount'), 2);
        $net = round((float) $o->get('amountNet'), 2);

        if ($net == 0.0 && $vat == 0.0) {
            $net = $gross;
        } elseif (abs(round($net + $vat, 2) - $gross) > 0.02) {
            $net = round($gross - $vat, 2); // a stale split never wins over the total
        }

        $rate = $net > 0 ? round($vat / $net * 100, 2) : 0.0;

        // 3.12 / 14.88 = 20.97 % is cent rounding of 21 %: report the nominal whole rate when within rounding.
        if ($rate > 0 && abs($rate - round($rate)) <= 0.25) {
            $rate = (float) round($rate);
        }

        $items = $this->em->getRDBRepository('OrderAddon')->where(['washOrderId' => $o->getId(), 'deleted' => false])
            ->order('sortOrder', 'ASC')->find();
        $raw = [];

        foreach ($items as $it) {
            $raw[] = [(string) $it->get('name'), round((float) $it->get('price'), 2), (bool) $it->get('isBase')];
        }

        if (!$raw) {
            $raw[] = [(string) ($o->get('serviceName') ?: 'Wash'), $gross, true];
        }

        $parts = LineSplitter::split(array_column($raw, 1), $net, $vat);
        $lines = [];

        foreach ($raw as $i => [$name, , $isBase]) {
            $lines[] = [
                'kind' => $isBase ? 'base' : 'addon',
                'name' => mb_substr($name !== '' ? $name : 'Wash', 0, 150),
                'qty' => 1,
                'net' => $parts[$i]['net'],
                'vat' => $parts[$i]['vat'],
                'gross' => $parts[$i]['gross'],
            ];
        }

        $method = (string) ($o->get('paymentMethod') ?: '');
        $paid = $o->get('paymentStatus') === 'Paid' && in_array($method, ['Cash', 'Card'], true);
        $tz = $this->cfg->timeZone();
        $visit = $o->get('orderDateTime') ?: $o->get('finishedAt') ?: $o->get('createdAt');
        $date = $visit ? (new \DateTime((string) $visit, new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d') : date('Y-m-d');

        $doc = [
            'number' => mb_substr($this->cfg->str('accountingNumberPrefix') . (string) $o->get('name'), 0, 35),
            'date' => $date,
            'currency' => 'EUR',
            'party' => $this->party($o),
            'lines' => $lines,
            'net' => $net,
            'vat' => $vat,
            'gross' => $gross,
            'vatRate' => $vat > 0 ? $rate : 0.0,
            'paid' => $paid,
            'method' => $method,
            'dueDays' => $method === 'Invoice' ? max(0, (int) $this->cfg->get('accountingDueDays', 14)) : 0,
            'skip' => null,
        ];
        $doc['hash'] = self::hash($doc);

        return $doc;
    }

    /** What the program receives, fingerprinted: a later edit of the order shows up as drift in the summary. */
    public static function hash(array $doc): string
    {
        $keep = [
            $doc['number'], $doc['date'], $doc['gross'], $doc['net'], $doc['vat'], $doc['party']['regNo'] ?? '', $doc['party']['general'] ?? false,
            array_map(fn($l) => [$l['name'], $l['gross']], $doc['lines']),
        ];

        return sha1(json_encode($keep, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** @return array<string, mixed> */
    public function party(Entity $o): array
    {
        $general = [
            'general' => true,
            'isCompany' => false,
            'name' => mb_substr($this->cfg->str('accountingRetailName', 'Roku mazgāšana') ?: 'Roku mazgāšana', 0, 150),
            'regNo' => '', 'vatNo' => '', 'address' => '', 'email' => '',
        ];
        $cid = (string) $o->get('customerId');

        if ($cid === '') {
            return $general;
        }

        $c = $this->em->getEntityById('Customer', $cid);
        $reg = $c ? preg_replace('/\s+/', '', (string) $c->get('regNumber')) : '';

        if (!$c || !$c->get('isCorporate') || $reg === '') {
            return $general;
        }

        return [
            'general' => false,
            'isCompany' => true,
            'name' => mb_substr((string) $c->get('name'), 0, 150),
            'regNo' => (string) $reg,
            'vatNo' => preg_replace('/\s+/', '', (string) $c->get('pvnNumber')),
            'address' => mb_substr((string) $c->get('legalAddress'), 0, 250),
            'email' => (string) $c->get('email'),
        ];
    }
}
