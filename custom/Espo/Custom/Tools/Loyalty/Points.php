<?php

namespace Espo\Custom\Tools\Loyalty;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\EntityManager;

/**
 * Loyalty points: the ONE door is `move()` (the pattern of Tools/Inventory/Stock::move, docs/LOYALTY.md).
 *
 * `Customer.loyaltyPoints` is server-owned and is changed ONLY here: inside a transaction, with the customer row locked, by one
 * atomic UPDATE, together with one appended `LoyaltyLedger` row (kind Earn / Redeem / Adjust / Expire / Referral / Reverse; signed
 * points, balance after, order, note, who). The journal is append-only (`Hooks/LoyaltyLedger/Immutable`; the entity API reads only).
 *
 * LOTS. Every row that ADDS points (Earn, Referral, a positive Adjust, a Reverse that hands points back) is a lot: `remaining`
 * points that expire at `expiresAt` (setting loyaltyPointsExpireDays, 0 = never). Spending (Redeem, a negative Adjust, a Reverse of
 * an earn) consumes the OLDEST unexpired lots first (FIFO by `seq`) in the same transaction and records which lots in `lotsJson`
 * ({lotId: points}); returning a redemption puts the points back into those same lots (a lot that has expired meanwhile is replaced
 * by a fresh lot on the Reverse row, with a new expiry). Overdue lots are expired (an Expire row each) by the next move of that
 * customer and by the daily job `LoyaltyExpire`, so what a customer can spend never includes an overdue lot.
 *
 * The balance never goes below 0: spending more than is available is `BadRequest insufficient_points`; reversing an earn whose points
 * were already spent takes back only what is still there (the shortfall is written in the row's note).
 */
class Points
{
    public const KINDS = ['Earn', 'Redeem', 'Adjust', 'Expire', 'Referral', 'Reverse'];

    public function __construct(private EntityManager $em, private LoyaltyConfig $cfg)
    {}

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function expiryFor(string $now): ?string
    {
        $days = $this->cfg->expireDays();

        return $days > 0 ? gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + $days * 86400) : null;
    }

    /**
     * @param array{orderId?: ?string, note?: ?string, uniqueKey?: ?string, expiresAt?: ?string, lotId?: ?string, lots?: array<string,int>} $o
     * @return array{before: int, after: int, delta: int, rowId: ?string, lots: array<string,int>}
     * @throws BadRequest
     */
    public function move(string $customerId, string $kind, int $points, array $o = []): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new BadRequest('invalid_kind');
        }

        $pdo = $this->em->getPDO();
        $tm = $this->em->getTransactionManager();
        $tm->start();

        try {
            $st = $pdo->prepare('SELECT loyalty_points FROM customer WHERE id = ? AND deleted = 0 FOR UPDATE');
            $st->execute([$customerId]);
            $bal = $st->fetchColumn();

            if ($bal === false) {
                throw new BadRequest('customer_not_found');
            }

            $now = $this->now();
            $start = (int) $bal;
            $bal = $start;

            // overdue lots go first, so they can never be spent
            $bal = $this->expireDueLocked($customerId, $bal, $now);

            $result = ['before' => $start, 'after' => $bal, 'delta' => 0, 'rowId' => null, 'lots' => []];

            if ($kind !== 'Expire') {
                $result = $this->apply($customerId, $kind, $points, $o, $bal, $now) + ['before' => $start];
            }

            if ($result['after'] < 0) {
                throw new BadRequest('insufficient_points');
            }

            $pdo->prepare('UPDATE customer SET loyalty_points = ? WHERE id = ?')->execute([$result['after'], $customerId]);

            $tm->commit();

            return $result;
        } catch (\Throwable $e) {
            $tm->rollback();

            throw $e;
        }
    }

    /** @return array{after: int, delta: int, rowId: ?string, lots: array<string,int>} */
    private function apply(string $customerId, string $kind, int $points, array $o, int $bal, string $now): array
    {
        $lots = [];
        $remaining = 0;
        $expiresAt = null;
        $note = isset($o['note']) ? (string) $o['note'] : null;
        $delta = $points;

        if ($kind === 'Earn' || $kind === 'Referral') {
            if ($points <= 0) {
                throw new BadRequest('invalid_points');
            }

            $remaining = $points;
            $expiresAt = array_key_exists('expiresAt', $o) ? $o['expiresAt'] : $this->expiryFor($now);
        } elseif ($kind === 'Adjust') {
            if ($points === 0) {
                throw new BadRequest('invalid_points');
            }

            if ($points > 0) {
                $remaining = $points;
                $expiresAt = $this->expiryFor($now);
            } else {
                if ($bal + $points < 0) {
                    throw new BadRequest('insufficient_points');
                }

                $lots = $this->consume($customerId, -$points, $now, null);
            }
        } elseif ($kind === 'Redeem') {
            if ($points >= 0) {
                throw new BadRequest('invalid_points');
            }

            $lots = $this->consume($customerId, -$points, $now, null);

            if (array_sum($lots) < -$points) {
                throw new BadRequest('insufficient_points');
            }
        } elseif ($kind === 'Reverse') {
            if ($points < 0) {
                // an earn is taken back: from its own lot first, then the oldest; never more than is there
                $want = min(-$points, $bal);
                $lots = $want > 0 ? $this->consume($customerId, $want, $now, $o['lotId'] ?? null) : [];
                $got = (int) array_sum($lots);
                $delta = -$got;

                if ($got < -$points) {
                    $note = trim(($note ?? '') . ' [short ' . (-$points - $got) . ']');
                }
            } elseif ($points > 0) {
                // a redemption is returned: into the lots it came from while they live, the rest as a fresh lot
                $left = $points;

                foreach (($o['lots'] ?? []) as $lotId => $n) {
                    $n = min((int) $n, $left);

                    if ($n <= 0) {
                        continue;
                    }

                    $st = $this->em->getPDO()->prepare(
                        'UPDATE loyalty_ledger SET remaining = remaining + ? WHERE id = ? AND deleted = 0 AND (expires_at IS NULL OR expires_at > ?)'
                    );
                    $st->execute([$n, $lotId, $now]);

                    if ($st->rowCount() > 0) {
                        $left -= $n;
                    }
                }

                $remaining = $left;
                $expiresAt = $left > 0 ? $this->expiryFor($now) : null;
                $lots = $o['lots'] ?? [];
            } else {
                throw new BadRequest('invalid_points');
            }
        }

        if ($delta === 0 && $kind === 'Reverse') {
            return ['after' => $bal, 'delta' => 0, 'rowId' => null, 'lots' => []];
        }

        $after = $bal + $delta;
        $rowId = $this->insertRow($customerId, $kind, $delta, $after, $remaining, $expiresAt, $lots, $o['orderId'] ?? null, $note, $o['uniqueKey'] ?? null);

        return ['after' => $after, 'delta' => $delta, 'rowId' => $rowId, 'lots' => $lots];
    }

    /**
     * Take $need points from the oldest unexpired lots (the lot $firstLotId first). Rows are locked by the customer lock already held.
     *
     * @return array<string,int> lotId => points taken
     */
    private function consume(string $customerId, int $need, string $now, ?string $firstLotId): array
    {
        $st = $this->em->getPDO()->prepare(
            'SELECT id, remaining FROM loyalty_ledger WHERE customer_id = :c AND deleted = 0 AND remaining > 0 ' .
            'AND (expires_at IS NULL OR expires_at > :n) ORDER BY (id = :f) DESC, seq ASC, id ASC FOR UPDATE'
        );
        $st->execute(['c' => $customerId, 'n' => $now, 'f' => $firstLotId ?? '-']);

        $taken = [];

        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $lot) {
            if ($need <= 0) {
                break;
            }

            $n = min($need, (int) $lot['remaining']);
            $this->em->getPDO()->prepare('UPDATE loyalty_ledger SET remaining = remaining - ? WHERE id = ?')->execute([$n, $lot['id']]);
            $taken[$lot['id']] = $n;
            $need -= $n;
        }

        return $taken;
    }

    /** Expire every overdue lot of the (locked) customer: one Expire row each. Returns the new balance. */
    private function expireDueLocked(string $customerId, int $bal, string $now): int
    {
        $st = $this->em->getPDO()->prepare(
            'SELECT id, remaining FROM loyalty_ledger WHERE customer_id = :c AND deleted = 0 AND remaining > 0 ' .
            'AND expires_at IS NOT NULL AND expires_at <= :n ORDER BY seq ASC FOR UPDATE'
        );
        $st->execute(['c' => $customerId, 'n' => $now]);

        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $lot) {
            $n = (int) $lot['remaining'];
            $this->em->getPDO()->prepare('UPDATE loyalty_ledger SET remaining = 0 WHERE id = ?')->execute([$lot['id']]);
            $bal -= $n;
            $this->insertRow($customerId, 'Expire', -$n, $bal, 0, null, [$lot['id'] => $n], null, null, null);
        }

        $this->em->getPDO()->prepare('UPDATE customer SET loyalty_points = ? WHERE id = ?')->execute([$bal, $customerId]);

        return $bal;
    }

    private function insertRow(
        string $customerId,
        string $kind,
        int $delta,
        int $after,
        int $remaining,
        ?string $expiresAt,
        array $lots,
        ?string $orderId,
        ?string $note,
        ?string $uniqueKey
    ): string {
        $st = $this->em->getPDO()->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM loyalty_ledger WHERE customer_id = ?');
        $st->execute([$customerId]);
        $seq = (int) $st->fetchColumn();

        $row = $this->em->getNewEntity('LoyaltyLedger');
        $row->set([
            'name' => $kind . ' ' . ($delta > 0 ? '+' : '') . $delta,
            'customerId' => $customerId,
            'kind' => $kind,
            'points' => $delta,
            'balanceAfter' => $after,
            'washOrderId' => $orderId,
            'note' => $note !== null ? mb_substr($note, 0, 250) : null,
            'remaining' => $remaining,
            'expiresAt' => $expiresAt,
            'lotsJson' => $lots ? json_encode($lots) : null,
            'uniqueKey' => $uniqueKey,
            'seq' => $seq,
        ]);
        $this->em->saveEntity($row, ['allowLedgerWrite' => true]);

        return $row->getId();
    }

    // ------------------------------------------------------------------ the daily job

    /**
     * Expire every overdue lot of every customer that has one (the job `LoyaltyExpire`, `bin/command loyalty-expire`).
     *
     * @return array{customers: int, points: int}
     */
    public function expireAll(bool $dry = false): array
    {
        $st = $this->em->getPDO()->prepare(
            'SELECT customer_id, SUM(remaining) AS pts FROM loyalty_ledger WHERE deleted = 0 AND remaining > 0 ' .
            'AND expires_at IS NOT NULL AND expires_at <= ? GROUP BY customer_id'
        );
        $st->execute([$this->now()]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $points = 0;

        foreach ($rows as $r) {
            $points += (int) $r['pts'];

            if (!$dry) {
                $this->move((string) $r['customer_id'], 'Expire', 0, ['note' => 'expired']);
            }
        }

        return ['customers' => count($rows), 'points' => $points];
    }

    // ------------------------------------------------------------------ reads

    /** Points the customer can spend now: the unexpired lots (an overdue lot is not spendable even before the job writes its Expire row). */
    public function spendable(string $customerId): int
    {
        $st = $this->em->getPDO()->prepare(
            'SELECT COALESCE(SUM(remaining), 0) FROM loyalty_ledger WHERE customer_id = ? AND deleted = 0 AND remaining > 0 ' .
            'AND (expires_at IS NULL OR expires_at > ?)'
        );
        $st->execute([$customerId, $this->now()]);

        return (int) $st->fetchColumn();
    }

    public function balance(string $customerId): int
    {
        $st = $this->em->getPDO()->prepare('SELECT loyalty_points FROM customer WHERE id = ? AND deleted = 0');
        $st->execute([$customerId]);

        return (int) $st->fetchColumn();
    }

    /** @return array{at: ?string, points: int} the soonest expiry among the live lots */
    public function nextExpiry(string $customerId): array
    {
        $st = $this->em->getPDO()->prepare(
            'SELECT MIN(expires_at) FROM loyalty_ledger WHERE customer_id = ? AND deleted = 0 AND remaining > 0 AND expires_at IS NOT NULL AND expires_at > ?'
        );
        $st->execute([$customerId, $this->now()]);
        $at = $st->fetchColumn() ?: null;

        if (!$at) {
            return ['at' => null, 'points' => 0];
        }

        $st = $this->em->getPDO()->prepare(
            'SELECT COALESCE(SUM(remaining), 0) FROM loyalty_ledger WHERE customer_id = ? AND deleted = 0 AND remaining > 0 AND expires_at = ?'
        );
        $st->execute([$customerId, $at]);

        return ['at' => (string) $at, 'points' => (int) $st->fetchColumn()];
    }

    // ------------------------------------------------------------------ the redemption arithmetic (pure)

    /** EUR value of $points. */
    public function valueOf(int $points): float
    {
        return round($points * $this->cfg->pointValue(), 2);
    }

    /**
     * How many points may be spent on an order whose total (after a promo / free wash, before points) is $preTotal:
     * at most the balance, at most loyaltyRedeemMaxPercent of the total, never beyond the total.
     */
    public function maxFor(float $preTotal, int $spendable): int
    {
        if ($preTotal <= 0.004) {
            return 0;
        }

        $capEur = min($preTotal, round($preTotal * $this->cfg->redeemMaxPercent() / 100, 2));
        $byValue = (int) floor(($capEur + 0.0000001) / $this->cfg->pointValue());

        return max(0, min($spendable, $byValue));
    }

    /**
     * Is a request for $n points acceptable? Returns null or a stable reason:
     * points_off, points_min, points_balance, points_max, points_nothing
     */
    public function problem(int $n, float $preTotal, int $spendable): ?string
    {
        if ($n <= 0) {
            return null;
        }

        if (!$this->cfg->pointsOn()) {
            return 'points_off';
        }

        if ($n < $this->cfg->redeemMin()) {
            return 'points_min';
        }

        if ($n > $spendable) {
            return 'points_balance';
        }

        if ($preTotal <= 0.004) {
            return 'points_nothing';
        }

        if ($n > $this->maxFor($preTotal, PHP_INT_MAX)) {
            return 'points_max';
        }

        return null;
    }

    /** Points earned by an order paid $paid EUR (floor; 0 when the scheme is off or nothing was paid). */
    public function earnFor(float $paid): int
    {
        $rate = $this->cfg->earnPerEur();

        return $paid > 0.004 && $rate > 0 ? (int) floor(round($paid * $rate, 6)) : 0;
    }
}
