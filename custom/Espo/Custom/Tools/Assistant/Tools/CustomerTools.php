<?php

namespace Espo\Custom\Tools\Assistant\Tools;

use Espo\Custom\Controllers\CarWash;
use Espo\Custom\Tools\Assistant\InternalRequest;
use Espo\Custom\Tools\Assistant\Mask;
use Espo\Custom\Tools\Assistant\Toolset;
use Espo\Custom\Tools\Assistant\Untrusted;
use Espo\ORM\Entity;

/**
 * People and cars. A private customer is a pseudonym (K-n); a company keeps its name. Names and
 * masked contacts appear only when the installation's PII level allows it, the model asks for
 * them and the asking user's role may read the field. Every record is checked against the
 * user's own ACL one by one.
 */
class CustomerTools extends Toolset
{
    public function specs(): array
    {
        return [
            [
                'name' => 'find_customer',
                'description' => 'Find a customer or a car by plate number or by (part of) a name. Returns up to 5 matches: a reference like K-3 for private persons (or the company name), plates, number of visits, money spent, last visit, debt and which marketing channels they consented to. Private persons are NOT named unless includeName is set and the installation allows it.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Plate number or name fragment, at least 2 characters.'],
                    'includeName' => ['type' => 'boolean', 'description' => 'Only if the question needs the person\'s name.'],
                    'includeContact' => ['type' => 'boolean', 'description' => 'Only if the question needs a (masked) phone/e-mail.'],
                ], 'required' => ['query'], 'additionalProperties' => false],
            ],
            [
                'name' => 'customer_history',
                'description' => 'Visit history of one customer: date, services (base wash and add-ons), price, payment, plate. Identify the customer by the K-n reference (or company name) from an earlier result, or by plate.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'customer' => ['type' => 'string', 'description' => 'K-n reference, plate number or company name.'],
                    'limit' => ['type' => 'integer', 'description' => 'Most recent visits, 1-20 (default 10).'],
                ], 'required' => ['customer'], 'additionalProperties' => false],
            ],
            [
                'name' => 'inactive_customers',
                'description' => 'Customers who used to come (at least one completed order) but have not been for N days and have no upcoming reservation: the list for a win-back mailing. Returns references, last visit, visits, spent, which channels they consented to, and counts of how many can actually be reached per channel.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'days' => ['type' => 'integer', 'description' => 'Not seen for at least this many days, 7-730 (default 60).'],
                    'limit' => ['type' => 'integer', 'description' => 'List length, 1-100 (default 30). Counts always cover everyone.'],
                    'segment' => ['type' => 'string', 'enum' => ['All', 'Corporate', 'Individual']],
                ], 'additionalProperties' => false],
            ],
            [
                'name' => 'unpaid_orders',
                'description' => 'Orders that are not paid (Unpaid or Invoiced), not cancelled: number, date, amount, how many days old, method, customer reference. Totals cover all of them; the list is the oldest first.',
                'input_schema' => ['type' => 'object', 'properties' => $this->periodSchema(' Default: last 90 days.') + [
                    'limit' => ['type' => 'integer', 'description' => '1-50 (default 25).'],
                ], 'additionalProperties' => false],
            ],
        ];
    }

    public function run(string $name, array $args): array
    {
        return match ($name) {
            'find_customer' => $this->find($args),
            'customer_history' => $this->history($args),
            'inactive_customers' => $this->inactive($args),
            'unpaid_orders' => $this->unpaid($args),
            default => $this->fail(404, 'unknown tool'),
        };
    }

    private function consent(Entity $c): array
    {
        return [
            'whatsapp' => (bool) $c->get('allowWhatsapp'),
            'email' => (bool) $c->get('allowEmail'),
            'phone' => (bool) $c->get('allowPhone'),
        ];
    }

    private function find(array $args): array
    {
        if (!$this->ctx->canRead('Customer')) {
            return $this->denied('customers');
        }

        $q = Untrusted::clean((string) ($args['query'] ?? ''), 60);

        if (mb_strlen($q) < 2) {
            return $this->fail(400, 'query must be at least 2 characters.');
        }

        $em = $this->ctx->em;
        $ids = [];
        $plate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $q));

        if (strlen($plate) >= 2) {
            foreach ($em->getRDBRepository('Vehicle')->where(['name*' => $plate . '%', 'deleted' => false])->limit(0, 10)->find() as $v) {
                if ($v->get('customerId')) {
                    $ids[$v->get('customerId')] = true;
                }
            }
        }

        foreach ($em->getRDBRepository('Customer')->where(['name*' => '%' . $q . '%', 'deleted' => false])->limit(0, 10)->find() as $c) {
            $ids[$c->getId()] = true;
        }

        $wantName = !empty($args['includeName']);
        $wantContact = !empty($args['includeContact']) && $this->ctx->cfg->piiLevel() === 'contacts';
        $matches = [];
        $cw = $this->ctx->factory->create(CarWash::class);

        foreach (array_keys($ids) as $id) {
            $c = $em->getEntityById('Customer', $id);

            if (!$c || !$this->ctx->canSee($c) || count($matches) >= 5) {
                continue;
            }

            $sum = json_decode((string) json_encode($cw->postActionCustomerSummary(new InternalRequest(['customerId' => $id]))), true) ?: [];
            $plates = [];

            foreach ($em->getRDBRepository('Vehicle')->where(['customerId' => $id, 'deleted' => false])->limit(0, 6)->find() as $v) {
                $plates[] = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $v->get('name')));
            }

            $m = $this->ctx->customerRef($c, $wantName) + [
                'corporate' => (bool) $c->get('isCorporate'),
                'hasContract' => (bool) $c->get('hasContract'),
                'plates' => $plates,
                'visits' => $sum['visits'] ?? 0,
                'spent' => $sum['spent'] ?? 0,
                'debt' => $sum['debt'] ?? 0,
                'lastVisit' => $this->ctx->localDate($sum['lastVisit'] ?? null, 'Y-m-d'),
                'consent' => $this->consent($c),
            ];

            if ($wantContact && $this->ctx->fieldOk('Customer', 'phone')) {
                $m['phoneMasked'] = $c->get('phone') ? Mask::phone((string) $c->get('phone')) : null;
                $m['emailMasked'] = $c->get('email') ? Mask::email((string) $c->get('email')) : null;
            }

            $matches[] = $m;
        }

        $out = [
            'source' => 'Customer search (Customer Lookup dashlet) + customer summary',
            'asOf' => $this->ctx->today(),
            'currency' => 'EUR',
            'matches' => $matches,
        ];

        if ($wantName && $this->ctx->cfg->piiLevel() === 'aggregate') {
            $out['note'] = 'Names of private persons are not shared with the assistant in this installation; use the K-n reference.';
        }

        if ($wantContact === false && !empty($args['includeContact'])) {
            $out['contactNote'] = 'Contact details are not shared with the assistant in this installation.';
        }

        return $out;
    }

    /** K-n, plate or company name -> customer entity the user may see. */
    private function resolve(string $ref): ?Entity
    {
        $ref = trim($ref);
        $em = $this->ctx->em;

        if (preg_match('/^K-\d+$/i', $ref)) {
            $id = $this->ctx->ps->idOf($ref);
            $c = $id ? $em->getEntityById('Customer', $id) : null;
        } else {
            $c = null;
            $plate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $ref));
            $v = $plate !== '' ? $em->getRDBRepository('Vehicle')->where(['name' => $plate, 'deleted' => false])->findOne() : null;

            if ($v && $v->get('customerId')) {
                $c = $em->getEntityById('Customer', $v->get('customerId'));
            }

            // a company name (companies are not pseudonymised)
            $c ??= $em->getRDBRepository('Customer')->where(['name' => $ref, 'isCorporate' => true, 'deleted' => false])->findOne();
        }

        return $c && $this->ctx->canSee($c) ? $c : null;
    }

    private function history(array $args): array
    {
        if (!$this->ctx->canRead('Customer') || !$this->ctx->canRead('WashOrder')) {
            return $this->denied('customers or orders');
        }

        $c = $this->resolve((string) ($args['customer'] ?? ''));

        if (!$c) {
            return $this->fail(404, 'No such customer (or your role cannot see it). Use a K-n reference from find_customer, a plate, or a company name.');
        }

        $limit = $this->clamp($args['limit'] ?? null, 1, 20, 10);
        $em = $this->ctx->em;
        $orders = $em->getRDBRepository('WashOrder')
            ->where(['customerId' => $c->getId(), 'status!=' => 'Cancelled', 'deleted' => false])
            ->order('orderDateTime', 'DESC')->limit(0, $limit)->find();
        $rows = [];

        foreach ($orders as $o) {
            if (!$this->ctx->canSee($o)) {
                continue;
            }

            $lines = [];

            foreach ($em->getRDBRepository('OrderAddon')->where(['washOrderId' => $o->getId(), 'deleted' => false])->order('sortOrder')->find() as $l) {
                $lines[] = Untrusted::clean((string) $l->get('name'), 50);
            }

            if (!$lines && $o->get('serviceName')) {
                $lines[] = Untrusted::clean((string) $o->get('serviceName'), 50);
            }

            $rows[] = [
                'date' => $this->ctx->localDate($o->get('orderDateTime')),
                'status' => (string) $o->get('status'),
                'services' => $lines,
                'price' => round((float) $o->get('finalPrice'), 2),
                'payment' => (string) $o->get('paymentStatus'),
                'plate' => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $o->get('vehicleName'))),
            ];
        }

        return $this->ctx->customerRef($c) + [
            'source' => 'Customer order history',
            'asOf' => $this->ctx->today(),
            'currency' => 'EUR',
            'visits' => $rows,
        ];
    }

    private function inactive(array $args): array
    {
        if (!$this->ctx->canRead('Customer') || !$this->ctx->canRead('WashOrder')) {
            return $this->denied('customers or orders');
        }

        $days = $this->clamp($args['days'] ?? null, 7, 730, 60);
        $limit = $this->clamp($args['limit'] ?? null, 1, 100, 30);
        $segment = in_array($args['segment'] ?? 'All', ['All', 'Corporate', 'Individual'], true) ? ($args['segment'] ?? 'All') : 'All';
        $em = $this->ctx->em;
        $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $query = $em->getQueryBuilder()->select()->from('WashOrder')
            ->select(['customerId', ['MAX:orderDateTime', 'lastVisit'], ['COUNT:id', 'visits'], ['SUM:finalPrice', 'spent']])
            ->where(['status' => 'Completed', 'deleted' => false, 'customerId!=' => null])
            ->group(['customerId'])
            ->build();

        $upcoming = [];

        foreach ($em->getRDBRepository('WashOrder')->select(['customerId'])->where([
            'status' => ['Reserved', 'New', 'In Progress'], 'orderDateTime>=' => gmdate('Y-m-d H:i:s', time() - 86400), 'deleted' => false, 'customerId!=' => null,
        ])->find() as $o) {
            $upcoming[$o->get('customerId')] = true;
        }

        $cands = [];

        foreach ($em->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($r['lastVisit'] < $cut && empty($upcoming[$r['customerId']])) {
                $cands[] = $r;
            }
        }

        usort($cands, fn($a, $b) => [(int) $b['visits'], $a['lastVisit']] <=> [(int) $a['visits'], $b['lastVisit']]);

        $total = 0;
        $reach = ['whatsapp' => 0, 'email' => 0, 'phone' => 0];
        $list = [];

        foreach ($cands as $r) {
            $c = $em->getEntityById('Customer', $r['customerId']);

            if (!$c || !$this->ctx->canSee($c)) {
                continue;
            }

            $corp = (bool) $c->get('isCorporate');

            if (($segment === 'Corporate' && !$corp) || ($segment === 'Individual' && $corp)) {
                continue;
            }

            $total++;
            $cons = $this->consent($c);
            $can = [
                'whatsapp' => $cons['whatsapp'] && (string) $c->get('phone') !== '',
                'email' => $cons['email'] && (string) $c->get('email') !== '',
                'phone' => $cons['phone'] && (string) $c->get('phone') !== '',
            ];

            foreach ($can as $k => $v) {
                $reach[$k] += $v ? 1 : 0;
            }

            if (count($list) < $limit) {
                $last = $this->ctx->localDate($r['lastVisit'], 'Y-m-d');
                $list[] = $this->ctx->customerRef($c) + [
                    'lastVisit' => $last,
                    'daysSince' => (int) floor((time() - strtotime($r['lastVisit'] . ' UTC')) / 86400),
                    'visits' => (int) $r['visits'],
                    'spent' => round((float) $r['spent'], 2),
                    'reachable' => $can,
                ];
            }
        }

        return [
            'source' => 'Completed orders grouped by customer (last visit older than N days, no upcoming order)',
            'asOf' => $this->ctx->today(),
            'currency' => 'EUR',
            'days' => $days,
            'segment' => $segment,
            'totalCustomers' => $total,
            'reachableByChannel' => $reach,
            'listed' => count($list),
            'customers' => $list,
            'note' => 'reachable = consented to that channel AND has the contact detail. A mailing only reaches those.',
        ];
    }

    private function unpaid(array $args): array
    {
        if (!$this->ctx->canRead('WashOrder')) {
            return $this->denied('orders');
        }

        $p = $this->period($args, '-90 days');

        if (!$p) {
            return $this->fail(400, 'dateFrom/dateTo must be YYYY-MM-DD, dateTo not before dateFrom, at most 400 days.');
        }

        $limit = $this->clamp($args['limit'] ?? null, 1, 50, 25);
        $em = $this->ctx->em;
        $orders = $em->getRDBRepository('WashOrder')->where([
            'status!=' => 'Cancelled',
            'paymentStatus!=' => 'Paid',
            'orderDateTime>=' => $this->ctx->utc($p[0] . ' 00:00:00'),
            'orderDateTime<=' => $this->ctx->utc($p[1] . ' 23:59:59'),
            'deleted' => false,
        ])->order('orderDateTime')->find();

        $count = 0;
        $sum = 0.0;
        $rows = [];
        $cache = [];

        foreach ($orders as $o) {
            if (!$this->ctx->canSee($o)) {
                continue;
            }

            $count++;
            $price = (float) $o->get('finalPrice');
            $sum += $price;

            if (count($rows) >= $limit) {
                continue;
            }

            $cid = (string) $o->get('customerId');
            $c = $cid ? ($cache[$cid] ??= $em->getEntityById('Customer', $cid)) : null;
            $rows[] = ($c && $this->ctx->canSee($c) ? $this->ctx->customerRef($c) : ['customer' => 'none']) + [
                'order' => (string) $o->get('name'),
                'date' => $this->ctx->localDate($o->get('orderDateTime'), 'Y-m-d'),
                'daysOld' => (int) floor((time() - strtotime((string) $o->get('orderDateTime') . ' UTC')) / 86400),
                'status' => (string) $o->get('status'),
                'amount' => round($price, 2),
                'method' => (string) $o->get('paymentMethod'),
                'payment' => (string) $o->get('paymentStatus'),
            ];
        }

        return [
            'source' => 'WashOrder list filtered by paymentStatus != Paid',
            'asOf' => $this->ctx->today(),
            'period' => ['from' => $p[0], 'to' => $p[1]],
            'currency' => 'EUR',
            'ordersCount' => $count,
            'totalUnpaid' => round($sum, 2),
            'listed' => count($rows),
            'orders' => $rows,
        ];
    }
}
