<?php

namespace Espo\Custom\Tools\Loyalty;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Promo codes: ONE class validates a code and runs its ledger (CLAUDE.md "Loyalty", docs/LOYALTY.md).
 *
 * Reasons are stable codes; the UI translates them (`Loyalty` i18n scope, `reasons`):
 *   unknown, inactive, not_started, expired, exhausted, customer_limit, min_amount, first_visit_only
 * A PUBLIC caller (the booking form, the customer chat) never gets one of them: `publicOk()` collapses everything
 * that could tell a visitor which codes exist into a plain yes / no.
 *
 * The ledger is `PromoRedemption`, one row per order: Reserved (applied to an open order) -> Used (the order was completed;
 * `PromoCode.usedCount` +1, exactly once) -> Released (the order was cancelled, deleted or lost its code; Used gives the count
 * back). Limits count Reserved + Used, so two open orders cannot both take the last use. The check + insert run under one
 * MySQL lock per code (race-safe).
 */
class Promo
{
    public const REASONS = ['unknown', 'inactive', 'not_started', 'expired', 'exhausted', 'customer_limit', 'min_amount', 'first_visit_only'];

    public function __construct(private EntityManager $em, private LoyaltyConfig $cfg)
    {}

    /** Upper case, only A-Z 0-9 and "-" (spaces and other marks dropped). */
    public static function normalize(string $code): string
    {
        return preg_replace('/[^A-Z0-9-]/', '', strtoupper(trim($code))) ?? '';
    }

    public static function isValidCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Z0-9-]{3,24}$/', $code);
    }

    private function sql(string $statement, array $params = []): \PDOStatement
    {
        $st = $this->em->getPDO()->prepare($statement);
        $st->execute($params);

        return $st;
    }

    public function find(string $code): ?Entity
    {
        $code = self::normalize($code);

        if (!self::isValidCode($code)) {
            return null;
        }

        return $this->em->getRDBRepository('PromoCode')->where(['code' => $code, 'deleted' => false])->findOne();
    }

    /** Reservations + uses of a code (optionally of one customer), not counting the order $exceptOrderId itself. */
    public function holds(string $promoId, ?string $customerId = null, ?string $exceptOrderId = null): int
    {
        $q = "SELECT COUNT(*) FROM promo_redemption WHERE promo_code_id = :p AND deleted = 0 AND status IN ('Reserved','Used')";
        $params = ['p' => $promoId];

        if ($customerId !== null) {
            $q .= ' AND customer_id = :c';
            $params['c'] = $customerId;
        }

        if ($exceptOrderId) {
            $q .= ' AND wash_order_id <> :o';
            $params['o'] = $exceptOrderId;
        }

        return (int) $this->sql($q, $params)->fetchColumn();
    }

    private function hasCompletedVisit(string $customerId, ?string $exceptOrderId): bool
    {
        $q = "SELECT COUNT(*) FROM wash_order WHERE customer_id = :c AND deleted = 0 AND status = 'Completed'";
        $params = ['c' => $customerId];

        if ($exceptOrderId) {
            $q .= ' AND id <> :o';
            $params['o'] = $exceptOrderId;
        }

        return (int) $this->sql($q, $params)->fetchColumn() > 0;
    }

    /**
     * Why this code cannot be used for this customer / amount right now, or null when it can (staff level of detail).
     * $amount = the order lines' total BEFORE any discount; $exceptOrderId = the order being (re-)evaluated.
     */
    public function problem(?Entity $promo, ?string $customerId, float $amount, ?string $exceptOrderId = null): ?string
    {
        if (!$promo) {
            return 'unknown';
        }

        if (!$promo->get('active')) {
            return 'inactive';
        }

        $today = $this->cfg->today();

        if ($promo->get('validFrom') && $promo->get('validFrom') > $today) {
            return 'not_started';
        }

        if ($promo->get('validTo') && $promo->get('validTo') < $today) {
            return 'expired';
        }

        $max = (int) $promo->get('maxUses');

        if ($max > 0 && $this->holds($promo->getId(), null, $exceptOrderId) >= $max) {
            return 'exhausted';
        }

        $per = (int) $promo->get('perCustomerLimit');

        if ($customerId && $per > 0 && $this->holds($promo->getId(), $customerId, $exceptOrderId) >= $per) {
            return 'customer_limit';
        }

        if ($amount + 0.004 < (float) $promo->get('minAmount')) {
            return 'min_amount';
        }

        if ($promo->get('firstVisitOnly') && $customerId && $this->hasCompletedVisit($customerId, $exceptOrderId)) {
            return 'first_visit_only';
        }

        return null;
    }

    /** The discount in EUR for a total: Percent of it, or a Fixed sum capped at it; never negative, never above the total. */
    public function discount(Entity $promo, float $amount): float
    {
        $amount = max(0.0, $amount);
        $v = (float) $promo->get('value');
        $d = $promo->get('kind') === 'Fixed' ? $v : round($amount * min(100.0, $v) / 100, 2);

        return round(max(0.0, min($amount, $d)), 2);
    }

    /**
     * For PUBLIC callers: is this code worth accepting at all? Only the checks that need no knowledge of the customer
     * (an existing customer is never looked at from a public form). The visitor learns "ok" or "invalid", nothing else -
     * unknown, switched off, outside its dates and used up are indistinguishable.
     */
    public function publicOk(string $code): bool
    {
        $promo = $this->find($code);

        if (!$promo || !$promo->get('active')) {
            return false;
        }

        $today = $this->cfg->today();

        if (($promo->get('validFrom') && $promo->get('validFrom') > $today) || ($promo->get('validTo') && $promo->get('validTo') < $today)) {
            return false;
        }

        $max = (int) $promo->get('maxUses');

        return !($max > 0 && $this->holds($promo->getId()) >= $max);
    }

    /** Is there any code a visitor could use? (the booking page shows its optional field only then; no code is revealed) */
    public function anyPublic(): bool
    {
        $today = $this->cfg->today();
        $st = $this->sql(
            'SELECT COUNT(*) FROM promo_code WHERE deleted = 0 AND active = 1 AND (valid_from IS NULL OR valid_from <= :t) AND (valid_to IS NULL OR valid_to >= :t2)',
            ['t' => $today, 't2' => $today]
        );

        return (int) $st->fetchColumn() > 0;
    }

    // ------------------------------------------------------------------ ledger

    /** The ledger row of an order (any status) or null. */
    public function redemption(string $orderId): ?Entity
    {
        return $this->em->getRDBRepository('PromoRedemption')->where(['washOrderId' => $orderId, 'deleted' => false])->findOne();
    }

    private function lock(string $promoId, int $seconds = 5): bool
    {
        return (int) $this->sql('SELECT GET_LOCK(:k, ' . (int) $seconds . ')', ['k' => 'cw_promo_' . substr($promoId, 0, 40)])->fetchColumn() === 1;
    }

    private function unlock(string $promoId): void
    {
        $this->sql('SELECT RELEASE_LOCK(:k)', ['k' => 'cw_promo_' . substr($promoId, 0, 40)]);
    }

    /**
     * Take a use of the code for this order: re-check every limit under the code's lock, then write the Reserved row.
     * Idempotent for an order that already holds it. Returns null on success, else the reason it was refused.
     */
    public function reserve(Entity $promo, Entity $order, float $amount, float $discount): ?string
    {
        $orderId = $order->getId();
        $customerId = $order->get('customerId');

        if (!$this->lock($promo->getId())) {
            return 'exhausted';
        }

        try {
            $row = $this->redemption($orderId);

            if ($row && $row->get('promoCodeId') === $promo->getId() && in_array($row->get('status'), ['Reserved', 'Used'], true)) {
                return null;
            }

            // fresh read: usedCount / active may have changed since the caller looked
            $fresh = $this->em->getEntityById('PromoCode', $promo->getId());
            $why = $this->problem($fresh, $customerId, $amount, $orderId);

            if ($why !== null) {
                return $why;
            }

            if ($row) {
                $this->sql(
                    "UPDATE promo_redemption SET promo_code_id = :p, customer_id = :c, amount = :a, name = :nm, status = 'Reserved', modified_at = :n WHERE id = :id",
                    ['p' => $promo->getId(), 'c' => $customerId, 'a' => $discount, 'nm' => (string) $promo->get('code'), 'n' => gmdate('Y-m-d H:i:s'), 'id' => $row->getId()]
                );
            } else {
                $this->em->createEntity('PromoRedemption', [
                    'name' => (string) $promo->get('code'),
                    'promoCodeId' => $promo->getId(),
                    'washOrderId' => $orderId,
                    'customerId' => $customerId,
                    'amount' => $discount,
                    'status' => 'Reserved',
                ], ['allowLedgerWrite' => true]);
            }
        } finally {
            $this->unlock($promo->getId());
        }

        return null;
    }

    /** Order completed: Reserved -> Used, usedCount +1 - once (the status claim is the atomic step). */
    public function settle(string $orderId): void
    {
        $st = $this->sql("UPDATE promo_redemption SET status = 'Used', modified_at = :n WHERE wash_order_id = :o AND status = 'Reserved' AND deleted = 0", ['n' => gmdate('Y-m-d H:i:s'), 'o' => $orderId]);

        if ($st->rowCount() < 1) {
            return;
        }

        $row = $this->redemption($orderId);

        if ($row) {
            $this->sql('UPDATE promo_code SET used_count = used_count + 1 WHERE id = :id', ['id' => $row->get('promoCodeId')]);
        }
    }

    /** The order left Completed (re-opened): Used -> Reserved, the count comes back. */
    public function unsettle(string $orderId): void
    {
        $st = $this->sql("UPDATE promo_redemption SET status = 'Reserved', modified_at = :n WHERE wash_order_id = :o AND status = 'Used' AND deleted = 0", ['n' => gmdate('Y-m-d H:i:s'), 'o' => $orderId]);

        if ($st->rowCount() < 1) {
            return;
        }

        $row = $this->redemption($orderId);

        if ($row) {
            $this->sql('UPDATE promo_code SET used_count = GREATEST(used_count - 1, 0) WHERE id = :id', ['id' => $row->get('promoCodeId')]);
        }
    }

    /** Cancelled / deleted / the code was removed: the use is given back (a Used row also gives back its count). */
    public function release(string $orderId): void
    {
        $row = $this->redemption($orderId);

        if (!$row || $row->get('status') === 'Released') {
            return;
        }

        $was = $row->get('status');
        $st = $this->sql("UPDATE promo_redemption SET status = 'Released', modified_at = :n WHERE id = :id AND status = :s", ['n' => gmdate('Y-m-d H:i:s'), 'id' => $row->getId(), 's' => $was]);

        if ($st->rowCount() > 0 && $was === 'Used') {
            $this->sql('UPDATE promo_code SET used_count = GREATEST(used_count - 1, 0) WHERE id = :id', ['id' => $row->get('promoCodeId')]);
        }
    }
}
