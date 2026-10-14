<?php

namespace Espo\Custom\Tools\Loyalty;

use Espo\Core\Utils\Log;
use Espo\Custom\Tools\OrderPrice\Distributor;
use Espo\Custom\Tools\OrderPrice\Recalculator;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Loyalty discounts on an order: promo code OR every-N-th-free (docs/LOYALTY.md). The one place that decides, applies and takes back.
 *
 * NO second price path: a discount is applied to the order's OrderAddon lines (the one truth about the price) - the base-wash line
 * to 0 for a free wash with scope Base, otherwise every line scaled in proportion by `Distributor::fit()`, exact to the cent.
 * `Recalculator` then re-derives finalPrice, VAT and the printable HTML; the order only RECORDS what happened
 * (`loyaltyKind`, `loyaltyDiscount`, `loyaltyNote`, `loyaltyEvaluated`, and the lines' prices before the discount in
 * `loyaltyLinesJson`, so unticking the reward restores them).
 *
 * Lifecycle (Hooks/WashOrder/ApplyLoyalty calls onSave after every save):
 *   Reserved                       nothing (a booking is evaluated when the car actually arrives)
 *   leaves Reserved / created      evaluate once: staff can untick (`skipLoyalty`), or change `promoCode`, until Completed
 *   Completed                      promo Reserved -> Used (usedCount +1), the wash is counted / the free wash is spent - once
 *   re-opened                      the above is taken back; Cancelled / deleted: everything is given back
 * If a promo AND a free wash both apply, only the larger discount is used (a tie goes to the promo, which keeps the reward).
 */
class Loyalty
{
    public function __construct(
        private EntityManager $em,
        private LoyaltyConfig $cfg,
        private Promo $promo,
        private FreeWash $free,
        private Points $points,
        private Referral $referral,
        private Recalculator $recalculator,
        private Log $log
    ) {}

    public function enabled(): bool
    {
        return $this->cfg->enabled();
    }

    // ------------------------------------------------------------------ the decision (shared by preview and apply)

    /**
     * @return array{kind: string, discount: float, promoDiscount: float, freeDiscount: float, note: string, promo: ?Entity}
     *         kind: '' | Promo | FreeWash
     */
    private function plan(string $code, ?string $customerId, float $list, float $base, bool $skip, ?string $exceptOrderId, bool $subscriptionLike = false): array
    {
        $out = ['kind' => '', 'discount' => 0.0, 'promoDiscount' => 0.0, 'freeDiscount' => 0.0, 'note' => '', 'promo' => null];

        if ($skip) {
            $out['note'] = 'skipped';

            return $out;
        }

        if ($subscriptionLike || $list <= 0.004) {
            return $out;
        }

        if ($code !== '') {
            $p = $this->promo->find($code);
            $why = $this->promo->problem($p, $customerId, $list, $exceptOrderId);

            if ($why !== null) {
                $out['note'] = 'promo_' . $why;
            } else {
                $d = $this->promo->discount($p, $list);

                if ($d > 0.004) {
                    $out['promoDiscount'] = $d;
                    $out['promo'] = $p;
                } else {
                    $out['note'] = 'promo_no_effect';
                }
            }
        }

        if ($customerId && $list + 0.004 >= $this->cfg->freeMinPrice()) {
            $customer = $this->em->getEntityById('Customer', $customerId);
            $info = $this->free->info($customer);

            if ($info['freeAvailable']) {
                $out['freeDiscount'] = $this->cfg->freeScope() === 'Base' ? round(min(max(0.0, $base), $list), 2) : round($list, 2);
            }
        }

        $pd = $out['promoDiscount'];
        $fd = $out['freeDiscount'];

        if ($pd > 0 && $pd >= $fd) {
            $out['kind'] = 'Promo';
            $out['discount'] = $pd;

            if ($fd > 0) {
                $out['note'] = 'free_promo_better';
            }
        } elseif ($fd > 0.004) {
            $out['kind'] = 'FreeWash';
            $out['discount'] = $fd;

            if ($pd > 0) {
                $out['note'] = 'promo_free_better';
            }
        }

        return $out;
    }

    /**
     * What staff would get for this customer and amount: the intake form's live preview. $baseAmount = the base wash's price
     * (for a free wash with scope Base). Reasons are detailed - this is the staff side.
     *
     * @return array<string, mixed>
     */
    public function preview(string $code, ?string $customerId, float $amount, ?float $baseAmount, bool $skip, int $redeemPoints = 0): array
    {
        $code = Promo::normalize($code);
        $plan = $this->plan($code, $customerId, $amount, $baseAmount ?? $amount, $skip, null);
        $p = $code !== '' ? $this->promo->find($code) : null;
        $reason = ($code !== '' && str_starts_with($plan['note'], 'promo_') && !in_array($plan['note'], ['promo_free_better', 'promo_no_effect'], true))
            ? substr($plan['note'], 6) : null;

        if ($code !== '' && $plan['note'] === 'promo_no_effect') {
            $reason = 'no_effect';
        }

        $applied = $plan['kind'] === 'Promo' ? 'promo' : ($plan['kind'] === 'FreeWash' ? 'free' : 'none');
        $after = round(max(0.0, $amount - $plan['discount']), 2);
        $pts = $this->pointsPreview($customerId, $after, $redeemPoints);

        return [
            'applies' => $applied,
            'discount' => $plan['discount'],
            'points' => $pts,
            'total' => round(max(0.0, $after - $pts['discount']), 2),
            'note' => $plan['note'],
            'promo' => $code === '' ? null : [
                'code' => $code,
                'name' => $p ? (string) $p->get('name') : null,
                'ok' => $reason === null,
                'reason' => $reason,
                'discount' => $plan['promoDiscount'],
            ],
            'free' => ['available' => $plan['freeDiscount'] > 0, 'discount' => $plan['freeDiscount']],
        ];
    }

    /**
     * The points side of the intake preview: the balance, what it is worth, how many points this order may use and what the request
     * would do. Points are applied on top of the total that remains after a promo / free wash.
     *
     * @return array<string, mixed>
     */
    public function pointsPreview(?string $customerId, float $preTotal, int $requested): array
    {
        $on = $this->cfg->pointsOn();
        $balance = $customerId && $on ? $this->points->balance($customerId) : 0;
        $spendable = $customerId && $on ? $this->points->spendable($customerId) : 0;
        $max = $on ? $this->points->maxFor($preTotal, $spendable) : 0;
        $reason = $requested > 0 ? ($customerId ? $this->points->problem($requested, $preTotal, $spendable) : 'points_balance') : null;
        $applied = $requested > 0 && $reason === null;

        return [
            'enabled' => $on,
            'balance' => $balance,
            'spendable' => $spendable,
            'value' => $this->points->valueOf($balance),
            'pointValue' => $this->cfg->pointValue(),
            'minPoints' => $this->cfg->redeemMin(),
            'maxPercent' => $this->cfg->redeemMaxPercent(),
            'maxPoints' => $max,
            'requested' => $requested,
            'ok' => $applied,
            'reason' => $reason,
            'discount' => $applied ? $this->points->valueOf($requested) : 0.0,
        ];
    }

    // ------------------------------------------------------------------ order helpers

    private function fresh(Entity $order): Entity
    {
        return $this->em->getEntityById('WashOrder', $order->getId()) ?? $order;
    }

    /** Direct write (no hooks) of the order's own loyalty columns. */
    private function writeOrder(string $id, array $set): void
    {
        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in('WashOrder')->set($set)->where(['id' => $id])->build()
        );
    }

    /** @return list<Entity> */
    private function lines(string $orderId): array
    {
        $out = [];

        foreach ($this->em->getRDBRepository('OrderAddon')->where(['washOrderId' => $orderId, 'deleted' => false])->order('sortOrder')->find() as $l) {
            $out[] = $l;
        }

        return $out;
    }

    /** Copy what changed in the database back into the entity the save is working with (it is serialised to the client). */
    private function syncBack(Entity $order): void
    {
        $o = $this->fresh($order);

        if ($o === $order) {
            return;
        }

        foreach (['finalPrice', 'amountNet', 'vatAmount', 'linesHtml', 'totalsHtml', 'loyaltyKind', 'loyaltyDiscount', 'loyaltyNote', 'loyaltyEvaluated', 'loyaltyCounted',
            'pointsRedeemed', 'pointsDiscount', 'pointsEarned', 'pointsNote', 'pointsEvaluated', 'pointsSettled', 'referralNote', 'referralResolved'] as $k) {
            $order->set($k, $o->get($k));
            $order->setFetched($k, $o->get($k));
        }
    }

    // ------------------------------------------------------------------ hooks

    public function onSave(Entity $order, bool $justCreated = false): void
    {
        if (!$this->cfg->enabled()) {
            return;
        }

        $isNew = $justCreated || $order->isNew();
        $statusChanged = $isNew || $order->isAttributeChanged('status');
        $st = (string) $order->get('status');
        $o = $this->fresh($order);

        if ($st === 'Cancelled' || $o->get('noShow')) {
            // what completing paid out is taken back, and the points reserved on the order are returned
            $this->unearn($o);
            $this->referral->onLeftCompleted($o->getId());

            if ($o->get('loyaltyEvaluated') || $o->get('loyaltyKind') || (int) $o->get('loyaltyCounted') || (int) $o->get('pointsRedeemed') > 0) {
                $this->revert($o);
                $this->syncBack($order);
            }

            return;
        }

        // re-opened after being completed: take back what completing did (the promo use goes back to Reserved, the counters are rewound)
        if ($statusChanged && !$isNew && $st !== 'Completed' && (string) $order->getFetched('status') === 'Completed') {
            $this->unearn($o);
            $this->referral->onLeftCompleted($o->getId());

            if ((int) $o->get('loyaltyCounted') > 0) {
                $this->free->unsettle($o);
            }

            $this->promo->unsettle($o->getId());

            if ($o->get('loyaltyKind') === 'FreeWash' && $o->get('customerId')) {
                $this->free->hold((string) $o->get('customerId'));
            }

            $o = $this->fresh($order);
        }

        if ($st === 'Reserved') {
            return;
        }

        // a code from the booking form / chat / intake: link the customer to the referrer now that the car is here
        if (trim((string) $o->get('referralCode')) !== '' && !$o->get('referralResolved')) {
            $this->referral->resolveOrder($o);
            $o = $this->fresh($order);
        }

        $inputsChanged = !$isNew && ($order->isAttributeChanged('skipLoyalty') || $order->isAttributeChanged('promoCode'));
        $pointsInputChanged = !$isNew && $order->isAttributeChanged('redeemPoints');

        // staff changed the points to use (or took them away) on an open order: give the old ones back first
        if ($pointsInputChanged && $st !== 'Completed' && ($o->get('pointsEvaluated') || (int) $o->get('pointsRedeemed') > 0)) {
            $this->revertPoints($o, true);
            $o = $this->fresh($order);
        }

        if ($o->get('loyaltyEvaluated') && $inputsChanged && $st !== 'Completed') {
            $this->revert($o);
            $o = $this->fresh($order);
        }

        if (!$o->get('loyaltyEvaluated') && ($isNew || $statusChanged || $inputsChanged)) {
            $this->evaluate($o);
            $o = $this->fresh($order);
        }

        // (points are applied while the order is open; typing them on an order that is already Completed changes nothing)
        if (
            !$o->get('pointsEvaluated') && (int) $o->get('redeemPoints') > 0
            && ($isNew || $statusChanged || (($inputsChanged || $pointsInputChanged) && $st !== 'Completed'))
        ) {
            $this->applyPoints($o);
            $o = $this->fresh($order);
        }

        if ($st === 'Completed' && ($isNew || $statusChanged)) {
            $this->settle($o);
        }

        $this->syncBack($order);
    }

    /** afterRemove of an order: give back every use it held (the lines are removed by RemoveLines). */
    public function onRemove(Entity $order): void
    {
        $o = $order;

        if ((int) $o->get('loyaltyCounted') > 0) {
            $this->free->unsettle($o);
        } elseif ($o->get('loyaltyKind') === 'FreeWash' && $o->get('customerId')) {
            $this->free->unhold((string) $o->get('customerId'));
        }

        $this->promo->release($o->getId());
        $this->unearn($o);
        $this->referral->onLeftCompleted($o->getId());

        if ((int) $o->get('pointsRedeemed') > 0) {
            $this->revertPoints($o, false);
        }
    }

    /** Called by createOrder after the lines and the typed total are in place (the hook skipped the order then). */
    public function applyToNew(Entity $order): void
    {
        $this->onSave($order, true);
    }

    // ------------------------------------------------------------------ evaluate / revert / settle

    private function evaluate(Entity $o): void
    {
        $id = $o->getId();
        $lines = $this->lines($id);

        $prices = [];
        $baseKey = null;
        $list = 0.0;

        foreach ($lines as $l) {
            $prices[$l->getId()] = (float) $l->get('price');
            $list += (float) $l->get('price');

            if ($l->get('isBase')) {
                $baseKey = $l->getId();
            }
        }

        $list = round($list, 2);
        $base = $baseKey !== null ? $prices[$baseKey] : 0.0;
        $customerId = $o->get('customerId') ?: null;
        $code = Promo::normalize((string) $o->get('promoCode'));
        $sub = (bool) ($o->get('subscriptionId') || $o->get('subscriptionSale'));

        $plan = $this->plan($code, $customerId, $list, $base, (bool) $o->get('skipLoyalty'), $id, $sub);

        // take the use (limits are re-checked under the lock); if the promo is lost to a race, fall back to the free wash
        if ($plan['kind'] === 'Promo') {
            $why = $this->promo->reserve($plan['promo'], $o, $list, $plan['discount']);

            if ($why !== null) {
                $plan = $this->plan('', $customerId, $list, $base, false, $id, $sub);
                $plan['note'] = 'promo_' . $why;
            }
        }

        if ($plan['kind'] === 'FreeWash' && !($customerId && $this->free->hold($customerId))) {
            $plan['kind'] = '';
            $plan['discount'] = 0.0;
        }

        $discount = $plan['kind'] === '' ? 0.0 : round($plan['discount'], 2);

        if ($discount <= 0.004) {
            $this->writeOrder($id, ['loyaltyEvaluated' => true, 'loyaltyKind' => '', 'loyaltyDiscount' => 0, 'loyaltyNote' => $plan['note'], 'loyaltyLinesJson' => null]);

            return;
        }

        if ($plan['kind'] === 'FreeWash' && $this->cfg->freeScope() === 'Base' && $baseKey !== null) {
            $new = $prices;
            $new[$baseKey] = 0.0;
        } else {
            $new = Distributor::fit($prices, $baseKey, round($list - $discount, 2));
        }

        // the order first (the printable HTML re-derived by each line save already shows the discount row)...
        $this->writeOrder($id, [
            'loyaltyEvaluated' => true,
            'loyaltyKind' => $plan['kind'],
            'loyaltyDiscount' => $discount,
            'loyaltyNote' => $plan['note'],
            'loyaltyLinesJson' => json_encode($prices),
        ]);

        // ...then the lines
        foreach ($lines as $l) {
            $np = $new[$l->getId()] ?? (float) $l->get('price');

            if (abs($np - (float) $l->get('price')) > 0.004) {
                $l->set('price', $np);
                $this->em->saveEntity($l);
            }
        }

        $this->recalculator->recalculate($id);
    }

    /** Undo an applied discount: the lines get their old prices back, the use is returned, the order is "not evaluated" again. */
    private function revert(Entity $o): void
    {
        $id = $o->getId();
        $wasSettled = (int) $o->get('loyaltyCounted');

        // points sit on top of the promo / free wash, so they come off first (the lines go back to their post-promo prices)
        if ($o->get('pointsEvaluated') || (int) $o->get('pointsRedeemed') > 0) {
            $this->revertPoints($o, true);
        }

        if ($wasSettled > 0) {
            $this->free->unsettle($o);
        }

        if ($o->get('loyaltyKind') === 'FreeWash' && $wasSettled !== FreeWash::FREE && $o->get('customerId')) {
            $this->free->unhold((string) $o->get('customerId'));
        }

        $this->promo->release($id);

        $json = (string) $o->get('loyaltyLinesJson');
        $orig = $json !== '' ? json_decode($json, true) : null;

        $this->writeOrder($id, ['loyaltyEvaluated' => false, 'loyaltyKind' => '', 'loyaltyDiscount' => 0, 'loyaltyNote' => '', 'loyaltyLinesJson' => null]);

        if (is_array($orig)) {
            foreach ($this->lines($id) as $l) {
                if (isset($orig[$l->getId()]) && abs((float) $orig[$l->getId()] - (float) $l->get('price')) > 0.004) {
                    $l->set('price', (float) $orig[$l->getId()]);
                    $this->em->saveEntity($l);
                }
            }
        }

        $this->recalculator->recalculate($id);
    }

    private function settle(Entity $o): void
    {
        $id = $o->getId();

        try {
            if ($o->get('loyaltyKind') === 'Promo') {
                $this->promo->settle($id);
            }

            // the wash as the customer saw it BEFORE any reward (a discounted wash still counts at its list size)
            $listTotal = round((float) $o->get('finalPrice') + (float) $o->get('loyaltyDiscount') + (float) $o->get('pointsDiscount'), 2);

            $this->free->settle($o, $listTotal);
            $o = $this->fresh($o);
            $this->earn($o);
            $this->referral->onCompleted($o);
        } catch (\Throwable $e) {
            $this->log->warning('Loyalty settle failed for order ' . $id . ': ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ points on an order

    /**
     * Spend `redeemPoints` on this order: validated against the balance / minimum / maximum, taken from the customer at once (the
     * points are RESERVED by the order until it is completed; cancel, no-show and delete give them back) and taken off the lines on top
     * of any promo / free wash through the same Distributor path. A refusal is written on the order (`pointsNote`) and changes nothing else.
     */
    private function applyPoints(Entity $o): void
    {
        $id = $o->getId();
        $n = (int) $o->get('redeemPoints');
        $cid = (string) $o->get('customerId');

        if ($n <= 0) {
            return;
        }

        $lines = $this->lines($id);
        $prices = [];
        $baseKey = null;
        $pre = 0.0;

        foreach ($lines as $l) {
            $prices[$l->getId()] = (float) $l->get('price');
            $pre += (float) $l->get('price');

            if ($l->get('isBase')) {
                $baseKey = $l->getId();
            }
        }

        $pre = round($pre, 2);
        $why = $cid === '' ? 'points_balance' : $this->points->problem($n, $pre, $this->points->spendable($cid));

        if ($why === null && ($o->get('subscriptionId') || $o->get('subscriptionSale'))) {
            $why = 'points_nothing';
        }

        $lots = [];

        if ($why === null) {
            try {
                $moved = $this->points->move($cid, 'Redeem', -$n, ['orderId' => $id, 'note' => 'order']);
                $lots = $moved['lots'];
            } catch (\Espo\Core\Exceptions\BadRequest $e) {
                $why = 'points_balance';
            }
        }

        if ($why !== null) {
            $this->writeOrder($id, ['pointsEvaluated' => true, 'pointsRedeemed' => 0, 'pointsDiscount' => 0, 'pointsNote' => $why]);

            return;
        }

        $discount = $this->points->valueOf($n);
        $new = Distributor::fit($prices, $baseKey, round($pre - $discount, 2));

        try {
            $this->writeOrder($id, [
                'pointsEvaluated' => true,
                'pointsRedeemed' => $n,
                'pointsDiscount' => $discount,
                'pointsNote' => '',
                'pointsLinesJson' => json_encode($prices),
                'pointsLotsJson' => json_encode($lots),
            ]);

            foreach ($lines as $l) {
                $np = $new[$l->getId()] ?? (float) $l->get('price');

                if (abs($np - (float) $l->get('price')) > 0.004) {
                    $l->set('price', $np);
                    $this->em->saveEntity($l);
                }
            }

            $this->recalculator->recalculate($id);
        } catch (\Throwable $e) {
            // the points were taken but the order could not carry them: give them back, never leave them in limbo
            $this->writeOrder($id, ['pointsEvaluated' => false, 'pointsRedeemed' => 0, 'pointsDiscount' => 0, 'pointsLinesJson' => null, 'pointsLotsJson' => null]);
            $this->points->move($cid, 'Reverse', $n, ['orderId' => $id, 'lots' => $lots, 'note' => 'returned']);

            throw $e;
        }
    }

    /** Give the reserved points back to the customer (into the lots they came from) and, when asked, restore the lines. */
    private function revertPoints(Entity $o, bool $restoreLines): void
    {
        $id = $o->getId();
        $cid = (string) $o->get('customerId');
        $n = (int) $o->get('pointsRedeemed');

        // claim: the points are returned once, however many times this runs
        if ($n > 0 && $cid !== '') {
            $claimed = $this->em->getPDO()->prepare('UPDATE wash_order SET points_redeemed = 0 WHERE id = ? AND points_redeemed > 0');
            $claimed->execute([$id]);

            if ($claimed->rowCount() > 0) {
                $lots = json_decode((string) $o->get('pointsLotsJson'), true);

                try {
                    $this->points->move($cid, 'Reverse', $n, ['orderId' => $id, 'lots' => is_array($lots) ? $lots : [], 'note' => 'returned']);
                } catch (\Throwable $e) {
                    $this->log->error('Loyalty: could not return ' . $n . ' points of order ' . $id . ': ' . $e->getMessage());
                }
            }
        }

        $orig = json_decode((string) $o->get('pointsLinesJson'), true);

        $this->writeOrder($id, ['pointsEvaluated' => false, 'pointsRedeemed' => 0, 'pointsDiscount' => 0, 'pointsNote' => '', 'pointsLinesJson' => null, 'pointsLotsJson' => null]);

        if ($restoreLines) {
            if (is_array($orig)) {
                foreach ($this->lines($id) as $l) {
                    if (isset($orig[$l->getId()]) && abs((float) $orig[$l->getId()] - (float) $l->get('price')) > 0.004) {
                        $l->set('price', (float) $orig[$l->getId()]);
                        $this->em->saveEntity($l);
                    }
                }
            }

            $this->recalculator->recalculate($id);
        }
    }

    /**
     * The order is Completed: the customer earns points on what was PAID (the total after the promo / free wash and after points). Once per
     * completion (an atomic flag on the order); nothing for a zero total, a subscription wash or sale, or a promo order when the setting says no.
     */
    private function earn(Entity $o): void
    {
        if (!$this->cfg->pointsOn() || !$o->get('customerId') || $o->get('subscriptionId') || $o->get('subscriptionSale') || $o->get('noShow')) {
            return;
        }

        if ($o->get('loyaltyKind') === 'Promo' && !$this->cfg->earnOnPromo()) {
            return;
        }

        $pts = $this->points->earnFor(round((float) $o->get('finalPrice'), 2));

        if ($pts <= 0) {
            return;
        }

        $claim = $this->em->getPDO()->prepare('UPDATE wash_order SET points_settled = 1 WHERE id = ? AND points_settled = 0 AND deleted = 0');
        $claim->execute([$o->getId()]);

        if ($claim->rowCount() < 1) {
            return;
        }

        try {
            $this->points->move((string) $o->get('customerId'), 'Earn', $pts, ['orderId' => $o->getId(), 'note' => 'order']);
            $this->writeOrder($o->getId(), ['pointsEarned' => $pts]);
        } catch (\Throwable $e) {
            $this->writeOrder($o->getId(), ['pointsSettled' => false]);
            $this->log->error('Loyalty: could not credit points for order ' . $o->getId() . ': ' . $e->getMessage());
        }
    }

    /** The order left Completed (re-opened, cancelled, deleted): take back the points it earned (only what is still there). */
    private function unearn(Entity $o): void
    {
        $pdo = $this->em->getPDO();
        $claim = $pdo->prepare('UPDATE wash_order SET points_settled = 0 WHERE id = ? AND points_settled = 1');
        $claim->execute([$o->getId()]);

        if ($claim->rowCount() < 1) {
            return;
        }

        $st = $pdo->prepare("SELECT id, points FROM loyalty_ledger WHERE wash_order_id = ? AND kind = 'Earn' AND deleted = 0 ORDER BY seq DESC, created_at DESC LIMIT 1");
        $st->execute([$o->getId()]);
        $earn = $st->fetch(\PDO::FETCH_ASSOC);
        $cid = (string) $o->get('customerId');

        if ($earn && $cid !== '' && (int) $earn['points'] > 0) {
            try {
                $this->points->move($cid, 'Reverse', -(int) $earn['points'], ['orderId' => $o->getId(), 'lotId' => $earn['id'], 'note' => 'earn reversed']);
            } catch (\Throwable $e) {
                $this->log->error('Loyalty: could not take back the points of order ' . $o->getId() . ': ' . $e->getMessage());
            }
        }

        $this->writeOrder($o->getId(), ['pointsEarned' => 0]);
    }
}
