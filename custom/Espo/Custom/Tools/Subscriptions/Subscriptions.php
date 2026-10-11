<?php

namespace Espo\Custom\Tools\Subscriptions;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Subscription rules - ALL of them live here, not in the UI (CLAUDE.md "Subscriptions").
 *
 *  kind Pack       N washes (`washesTotal`), `washesRemaining` counts down; optional expiry `validTo`
 *  kind Unlimited  any number of washes between `validFrom` and `validTo` (both required)
 *  optional `service`: the subscription covers only that base wash
 *
 * Money (one honest ledger): SELLING a subscription is an order (kind: subscription sale) with one priced line -
 * that is the revenue, with its own cash/card/invoice and till receipt. A wash PAID BY the subscription is a normal
 * order whose lines are brought to 0 EUR, so the same money is never counted twice; `SubscriptionUse.listPrice`
 * remembers what the wash would have cost.
 *
 * An order is tied to a subscription explicitly (WashOrder.subscription, chosen at intake or on the order):
 * nothing is ever consumed behind the operator's back. Redeemed ONCE per order (unique ledger row), on Completed;
 * cancelling the order gives the wash back.
 */
class Subscriptions
{
    public function __construct(private EntityManager $em, private Config $config, private Log $log)
    {}

    /** One prepared statement; returns the number of rows it changed (atomic counters need SQL arithmetic). */
    private function sql(string $statement, array $params): int
    {
        $st = $this->em->getPDO()->prepare($statement);
        $st->execute($params);

        return $st->rowCount();
    }

    public function today(): string
    {
        $tz = new \DateTimeZone((string) $this->config->get('timeZone', 'UTC') ?: 'UTC');

        return (new \DateTime('now', $tz))->format('Y-m-d');
    }

    /**
     * Why the subscription cannot pay for this wash right now, or null when it can.
     * $orderId is given when the order itself already holds the redemption (idempotent re-check).
     */
    public function problem(Entity $sub, ?string $customerId, ?string $serviceId, ?string $orderId = null): ?string
    {
        if ($customerId && $sub->get('customerId') !== $customerId) {
            return 'wrong_customer';
        }

        if ($orderId && $this->activeUse($orderId)) {
            return null;
        }

        if ($sub->get('status') === 'Cancelled') {
            return 'cancelled';
        }

        $today = $this->today();

        if ($sub->get('validFrom') && $sub->get('validFrom') > $today) {
            return 'not_started';
        }

        if ($sub->get('validTo') && $sub->get('validTo') < $today) {
            return 'expired';
        }

        if ($sub->get('serviceId') && $serviceId && $sub->get('serviceId') !== $serviceId) {
            return 'wrong_service';
        }

        if ($sub->get('kind') === 'Pack' && (int) $sub->get('washesRemaining') < 1) {
            return 'exhausted';
        }

        return null;
    }

    /** Effective status for display: Active / Exhausted / Expired / Cancelled (computed, so no cron is needed). */
    public function effectiveStatus(Entity $sub): string
    {
        if ($sub->get('status') === 'Cancelled') {
            return 'Cancelled';
        }

        $p = $this->problem($sub, null, null);

        return match ($p) {
            'expired' => 'Expired',
            'exhausted' => 'Exhausted',
            default => 'Active',
        };
    }

    /** @return array<string, mixed> */
    public function summary(Entity $sub): array
    {
        return [
            'id' => $sub->getId(),
            'name' => $sub->get('name'),
            'kind' => $sub->get('kind'),
            'remaining' => $sub->get('kind') === 'Pack' ? (int) $sub->get('washesRemaining') : null,
            'total' => $sub->get('kind') === 'Pack' ? (int) $sub->get('washesTotal') : null,
            'validFrom' => $sub->get('validFrom'),
            'validTo' => $sub->get('validTo'),
            'serviceId' => $sub->get('serviceId'),
            'serviceName' => $sub->get('serviceName'),
            'status' => $this->effectiveStatus($sub),
        ];
    }

    /** Subscriptions of a customer that can pay a wash of $serviceId today, soonest-expiring first. @return list<array<string, mixed>> */
    public function usableFor(string $customerId, ?string $serviceId = null): array
    {
        $out = [];
        $subs = $this->em->getRDBRepository('Subscription')->where(['customerId' => $customerId, 'deleted' => false])->find();

        foreach ($subs as $s) {
            if ($this->problem($s, $customerId, $serviceId) === null) {
                $out[] = $this->summary($s);
            }
        }

        usort($out, fn($a, $b) => [$a['validTo'] ?: '9999-12-31', $a['kind'] === 'Unlimited' ? 0 : 1] <=> [$b['validTo'] ?: '9999-12-31', $b['kind'] === 'Unlimited' ? 0 : 1]);

        return $out;
    }

    /** All subscriptions of a customer (any state) for the customer card. @return list<array<string, mixed>> */
    public function allFor(string $customerId): array
    {
        $out = [];
        $subs = $this->em->getRDBRepository('Subscription')->where(['customerId' => $customerId, 'deleted' => false])
            ->order('createdAt', 'DESC')->find();

        foreach ($subs as $s) {
            $out[] = $this->summary($s);
        }

        return $out;
    }

    private function activeUse(string $orderId): ?Entity
    {
        $u = $this->em->getRDBRepository('SubscriptionUse')->where(['washOrderId' => $orderId, 'deleted' => false])->findOne();

        return ($u && !$u->get('refundedAt')) ? $u : null;
    }

    /**
     * beforeSave of an order: refuse (409) a subscription that cannot pay this wash, BEFORE anything is stored,
     * so a Completed order never ends up half-redeemed.
     */
    public function assertUsable(Entity $order): void
    {
        $subId = $order->get('subscriptionId');

        if (!$subId || $order->get('status') === 'Cancelled') {
            return;
        }

        $sub = $this->em->getEntityById('Subscription', $subId);

        if (!$sub) {
            throw new BadRequest('subscription_not_found');
        }

        // Only a CHANGE of subscription or of the moment it is spent needs the check; a Completed order that
        // already holds its redemption can be re-saved freely.
        $relevant = $order->isNew() || $order->isAttributeChanged('subscriptionId')
            || ($order->isAttributeChanged('status') && $order->get('status') === 'Completed')
            || ($order->isAttributeChanged('serviceId'));

        if (!$relevant) {
            return;
        }

        $p = $this->problem($sub, $order->get('customerId'), $order->get('serviceId'), $order->isNew() ? null : $order->getId());

        if ($p !== null) {
            throw new Conflict('subscription_' . $p);
        }
    }

    /** afterSave of a Completed order tied to a subscription: spend one wash, once. */
    public function redeem(Entity $order): void
    {
        $subId = $order->get('subscriptionId');

        if (!$subId || $order->get('status') !== 'Completed') {
            return;
        }

        $sub = $this->em->getEntityById('Subscription', $subId);

        if (!$sub) {
            return;
        }

        $use = $this->em->getRDBRepository('SubscriptionUse')->where(['washOrderId' => $order->getId(), 'deleted' => false])->findOne();

        if ($use && !$use->get('refundedAt')) {
            return; // already spent for this order - idempotent
        }

        $pack = $sub->get('kind') === 'Pack';
        $now = date('Y-m-d H:i:s');

        // 1. claim the wash atomically (a Pack with 0 left cannot go negative, whoever races)
        if ($pack) {
            $rows = $this->sql('UPDATE subscription SET washes_remaining = washes_remaining - 1 WHERE id = :id AND washes_remaining > 0 AND deleted = 0', ['id' => $sub->getId()]);

            if ($rows < 1) {
                $this->log->warning('Subscription ' . $sub->getId() . ' had no wash left when order ' . $order->getId() . ' completed; the order keeps its price.');
                $this->em->getQueryExecutor()->execute(
                    $this->em->getQueryBuilder()->update()->in('WashOrder')->set(['subscriptionId' => null])->where(['id' => $order->getId()])->build()
                );
                $order->set('subscriptionId', null);

                return;
            }
        }

        // 2. the ledger row: one per order (unique index) - a concurrent second redeem fails here, and gives the wash back
        $listPrice = round((float) $order->get('finalPrice'), 2);

        try {
            if ($use) {
                $this->em->getQueryExecutor()->execute(
                    $this->em->getQueryBuilder()->update()->in('SubscriptionUse')
                        // listPrice stays what the wash was worth the first time: its lines are already 0 EUR now
                        ->set(['refundedAt' => null, 'subscriptionId' => $sub->getId(), 'usedAt' => $now])
                        ->where(['id' => $use->getId()])->build()
                );
            } else {
                $this->em->createEntity('SubscriptionUse', [
                    'name' => (string) $order->get('name'), 'subscriptionId' => $sub->getId(), 'washOrderId' => $order->getId(),
                    'usedAt' => $now, 'listPrice' => $listPrice, 'listPriceCurrency' => 'EUR',
                ], ['allowLedgerWrite' => true]);
            }
        } catch (\Throwable $e) {
            if ($pack) {
                $this->sql('UPDATE subscription SET washes_remaining = washes_remaining + 1 WHERE id = :id', ['id' => $sub->getId()]);
            }

            $this->log->warning('Subscription ledger write failed for order ' . $order->getId() . ': ' . $e->getMessage());

            return;
        }

        // 3. the wash is prepaid: bring its lines to 0 (the Recalculator re-derives price / VAT / printable HTML)
        $lines = $this->em->getRDBRepository('OrderAddon')->where(['washOrderId' => $order->getId(), 'deleted' => false])->find();
        $any = false;

        foreach ($lines as $line) {
            $any = true;

            if ((float) $line->get('price') != 0.0) {
                $line->set('price', 0);
                $this->em->saveEntity($line);
            }
        }

        $set = ['paymentStatus' => 'Paid', 'fiscalStatus' => 'NotRequired'];

        if (!$any) {
            $set += ['finalPrice' => 0, 'amountNet' => 0, 'vatAmount' => 0];
        }

        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in('WashOrder')->set($set)->where(['id' => $order->getId()])->build()
        );

        // the caller's in-memory order (it is serialised back to the client) must show what is stored now
        foreach (['paymentStatus' => 'Paid', 'fiscalStatus' => 'NotRequired', 'finalPrice' => 0, 'amountNet' => 0, 'vatAmount' => 0] as $k => $v) {
            $order->set($k, $v);
            $order->setFetched($k, $v);
        }

        $this->touchStatus($sub->getId());
    }

    /** afterSave of an order that left Completed (Cancelled / re-opened): give the wash back. */
    public function release(Entity $order): void
    {
        $use = $this->activeUse($order->getId());

        if (!$use || $order->get('status') === 'Completed') {
            return;
        }

        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in('SubscriptionUse')->set(['refundedAt' => date('Y-m-d H:i:s')])->where(['id' => $use->getId()])->build()
        );

        $sub = $this->em->getEntityById('Subscription', $use->get('subscriptionId'));

        if ($sub && $sub->get('kind') === 'Pack') {
            $this->sql('UPDATE subscription SET washes_remaining = LEAST(washes_total, washes_remaining + 1) WHERE id = :id', ['id' => $sub->getId()]);
        }

        if ($sub) {
            $this->touchStatus($sub->getId());
        }
    }

    /** The record behind an id (null when it does not exist) — the controller checks the caller's right on it. */
    public function find(string $entityType, string $id): ?\Espo\ORM\Entity
    {
        return $id === '' ? null : $this->em->getEntityById($entityType, $id);
    }

    public function cancel(string $id): void
    {
        if ($id === '' || !$this->em->getEntityById('Subscription', $id)) {
            throw new BadRequest('not_found');
        }

        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in('Subscription')->set(['status' => 'Cancelled'])->where(['id' => $id])->build()
        );
    }

    /** Keep the stored `status` honest after remaining/expiry changed (direct write, no hooks). */
    public function touchStatus(string $subId): void
    {
        $sub = $this->em->getEntityById('Subscription', $subId);

        if (!$sub || $sub->get('status') === 'Cancelled') {
            return;
        }

        $eff = $this->effectiveStatus($sub);

        if ($eff !== $sub->get('status')) {
            $this->em->getQueryExecutor()->execute(
                $this->em->getQueryBuilder()->update()->in('Subscription')->set(['status' => $eff])->where(['id' => $subId])->build()
            );
        }
    }

    /**
     * Sell a subscription: the Subscription record + the sale order (Completed, one priced line).
     * @param array<string, mixed> $in customerId, kind, washes?, validFrom?, validTo?, price, paymentMethod?, paid?, serviceId?, name?
     * @return array{subscription: Entity, order: Entity}
     */
    public function sell(array $in): array
    {
        $customer = !empty($in['customerId']) ? $this->em->getEntityById('Customer', (string) $in['customerId']) : null;

        if (!$customer) {
            throw new BadRequest('customer_required');
        }

        $kind = (string) ($in['kind'] ?? 'Pack');

        if (!in_array($kind, ['Pack', 'Unlimited'], true)) {
            throw new BadRequest('bad_kind');
        }

        $price = round((float) ($in['price'] ?? -1), 2);

        if ($price < 0) {
            throw new BadRequest('price_required');
        }

        $method = (string) ($in['paymentMethod'] ?? 'Cash');

        if (!in_array($method, ['Cash', 'Card', 'Invoice'], true)) {
            throw new BadRequest('bad_payment_method');
        }

        $from = $this->date($in['validFrom'] ?? null) ?: $this->today();
        $to = $this->date($in['validTo'] ?? null);

        if (!empty($in['validTo']) && !$to) {
            throw new BadRequest('bad_date');
        }

        $washes = (int) ($in['washes'] ?? 0);

        if ($kind === 'Pack' && $washes < 1) {
            throw new BadRequest('washes_required');
        }

        if ($kind === 'Unlimited' && !$to) {
            throw new BadRequest('valid_to_required');
        }

        if ($to && $to < $from) {
            throw new BadRequest('bad_period');
        }

        $serviceId = !empty($in['serviceId']) ? (string) $in['serviceId'] : null;

        if ($serviceId && !$this->em->getEntityById('WashService', $serviceId)) {
            throw new BadRequest('service_not_found');
        }

        $label = trim((string) ($in['name'] ?? '')) ?: ($kind === 'Pack' ? ('Abonements ' . $washes . ' x') : ('Abonements ' . $from . ' - ' . $to));

        $sub = $this->em->createEntity('Subscription', [
            'name' => $label, 'customerId' => $customer->getId(), 'kind' => $kind,
            'washesTotal' => $kind === 'Pack' ? $washes : null, 'washesRemaining' => $kind === 'Pack' ? $washes : null,
            'validFrom' => $from, 'validTo' => $to, 'price' => $price, 'priceCurrency' => 'EUR', 'serviceId' => $serviceId, 'status' => 'Active',
        ], ['allowLedgerWrite' => true]);

        $order = $this->em->createEntity('WashOrder', [
            'name' => 'SUB-' . date('ymd-His') . '-' . substr($sub->getId(), -4),
            'status' => 'Completed', 'orderDateTime' => date('Y-m-d H:i:s'), 'customerId' => $customer->getId(),
            'paymentMethod' => $method, 'subscriptionSale' => true, 'description' => $label,
            'isCorporate' => (bool) $customer->get('isCorporate'), 'hasContract' => (bool) $customer->get('hasContract'),
        ], ['explicitPaymentMethod' => true, 'skipNotify' => true]);

        $line = $this->em->createEntity('OrderAddon', [
            'name' => $label, 'washOrderId' => $order->getId(), 'price' => $price, 'priceCurrency' => 'EUR', 'sortOrder' => 10,
        ]);

        $set = ['saleOrderId' => $order->getId()];
        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in('Subscription')->set($set)->where(['id' => $sub->getId()])->build()
        );

        if (!empty($in['paid']) && $method !== 'Invoice') {
            $this->em->getQueryExecutor()->execute(
                $this->em->getQueryBuilder()->update()->in('WashOrder')->set(['paymentStatus' => 'Paid'])->where(['id' => $order->getId()])->build()
            );
        }

        $sub = $this->em->getEntityById('Subscription', $sub->getId());
        $order = $this->em->getEntityById('WashOrder', $order->getId());

        return ['subscription' => $sub, 'order' => $order, 'line' => $line];
    }

    private function date(mixed $v): ?string
    {
        $s = trim((string) $v);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }
}
