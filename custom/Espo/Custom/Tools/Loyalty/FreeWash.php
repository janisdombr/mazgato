<?php

namespace Espo\Custom\Tools\Loyalty;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * "Every N-th wash is free" (docs/LOYALTY.md has the exact rule and a worked example).
 *
 * Customer counters, all server-owned: `loyaltyWashes` (counted washes since the last free one, never above N-1),
 * `loyaltyFreeEarned`, `loyaltyFreeUsed`, `loyaltyFreeHeld` (1 = the reward is promised to an open order).
 *
 *   free wash available  <=>  loyaltyWashes >= N-1  and  not held
 *
 * Every counter change is ONE atomic UPDATE; whether an order was already counted is claimed on the order itself
 * (`loyaltyCounted`: 0 not yet, 1 counted as a paid wash, 2 settled but not counted, 3 settled as the free wash) so a repeat
 * of the completion hook, or two requests at once, count it once.
 */
class FreeWash
{
    public const COUNTED = 1;
    public const NOT_COUNTED = 2;
    public const FREE = 3;

    public function __construct(private EntityManager $em, private LoyaltyConfig $cfg)
    {}

    private function sql(string $statement, array $params = []): int
    {
        $st = $this->em->getPDO()->prepare($statement);
        $st->execute($params);

        return $st->rowCount();
    }

    /** @return array{enabled: bool, every: int, washes: int, toNext: int, freeAvailable: bool, held: bool, earned: int, used: int, scope: string} */
    public function info(?Entity $customer): array
    {
        $every = $this->cfg->freeEvery();
        $washes = $customer ? max(0, (int) $customer->get('loyaltyWashes')) : 0;
        $held = $customer ? (bool) $customer->get('loyaltyFreeHeld') : false;
        $ready = $every > 0 && $washes >= $every - 1;

        return [
            'enabled' => $every > 0,
            'every' => $every,
            'washes' => $washes,
            // washes still to pay before the free one (0 = the next wash is free)
            'toNext' => $every > 0 ? max(0, $every - 1 - $washes) : 0,
            'freeAvailable' => $ready && !$held,
            'held' => $held,
            'earned' => $customer ? (int) $customer->get('loyaltyFreeEarned') : 0,
            'used' => $customer ? (int) $customer->get('loyaltyFreeUsed') : 0,
            'scope' => $this->cfg->freeScope(),
        ];
    }

    /** Promise the reward to an order: atomic, only one open order can hold it. */
    public function hold(string $customerId): bool
    {
        $n1 = $this->cfg->freeEvery() - 1;

        if ($n1 < 1) {
            return false;
        }

        return $this->sql(
            'UPDATE customer SET loyalty_free_held = 1 WHERE id = :id AND deleted = 0 AND loyalty_free_held = 0 AND loyalty_washes >= :n',
            ['id' => $customerId, 'n' => $n1]
        ) > 0;
    }

    public function unhold(string $customerId): void
    {
        $this->sql('UPDATE customer SET loyalty_free_held = 0 WHERE id = :id', ['id' => $customerId]);
    }

    /** Does a wash of this size and kind take part in the scheme at all? $listTotal = the lines BEFORE any discount. */
    public function qualifies(Entity $order, float $listTotal): bool
    {
        return $this->cfg->freeEvery() > 0
            && (bool) $order->get('customerId')
            && !$order->get('subscriptionId')
            && !$order->get('subscriptionSale')
            && !$order->get('noShow')
            && $listTotal > 0.004
            && $listTotal + 0.004 >= $this->cfg->freeMinPrice();
    }

    /**
     * The order is Completed: count it (or settle the free wash). Returns what was recorded on the order (0 = nothing / already done).
     */
    public function settle(Entity $order, float $listTotal): int
    {
        $id = $order->getId();
        $cust = $order->get('customerId');
        $n1 = $this->cfg->freeEvery() - 1;

        if (!$cust || $n1 < 1) {
            return 0;
        }

        $isFree = $order->get('loyaltyKind') === 'FreeWash';

        if (!$isFree && !$this->qualifies($order, $listTotal)) {
            return 0;
        }

        // claim: only the first caller gets past this (placeholder value = "settled, not counted")
        if ($this->sql('UPDATE wash_order SET loyalty_counted = ' . self::NOT_COUNTED . ' WHERE id = :id AND loyalty_counted = 0 AND deleted = 0', ['id' => $id]) < 1) {
            return 0;
        }

        if ($isFree) {
            $this->sql(
                'UPDATE customer SET loyalty_washes = 0, loyalty_free_held = 0, loyalty_free_used = loyalty_free_used + 1 WHERE id = :id',
                ['id' => $cust]
            );
            $this->sql('UPDATE wash_order SET loyalty_counted = ' . self::FREE . ' WHERE id = :id', ['id' => $id]);

            return self::FREE;
        }

        // counted only below the cap (a pending reward is not stockpiled); earned is evaluated on the value BEFORE the increment
        $rows = $this->sql(
            'UPDATE customer SET loyalty_free_earned = loyalty_free_earned + IF(loyalty_washes + 1 >= :n, 1, 0), loyalty_washes = loyalty_washes + 1 '
            . 'WHERE id = :id AND deleted = 0 AND loyalty_washes < :n2',
            ['n' => $n1, 'id' => $cust, 'n2' => $n1]
        );

        if ($rows > 0) {
            $this->sql('UPDATE wash_order SET loyalty_counted = ' . self::COUNTED . ' WHERE id = :id', ['id' => $id]);

            return self::COUNTED;
        }

        return self::NOT_COUNTED;
    }

    /** The order left Completed (re-opened, cancelled) or is deleted: take back what settle() did. */
    public function unsettle(Entity $order): void
    {
        $id = $order->getId();
        $cust = $order->get('customerId');
        $v = (int) $order->get('loyaltyCounted');

        if ($v === 0 || !$cust) {
            return;
        }

        if ($this->sql('UPDATE wash_order SET loyalty_counted = 0 WHERE id = :id AND loyalty_counted = :v', ['id' => $id, 'v' => $v]) < 1) {
            return;
        }

        $n1 = $this->cfg->freeEvery() - 1;

        if ($v === self::COUNTED) {
            $this->sql('UPDATE customer SET loyalty_washes = GREATEST(loyalty_washes - 1, 0) WHERE id = :id', ['id' => $cust]);
        } elseif ($v === self::FREE && $n1 > 0) {
            // the reward was spent on this order: it is available again
            $this->sql(
                'UPDATE customer SET loyalty_washes = GREATEST(loyalty_washes, :n), loyalty_free_used = GREATEST(loyalty_free_used - 1, 0) WHERE id = :id',
                ['n' => $n1, 'id' => $cust]
            );
        }
    }
}
