<?php

namespace Espo\Custom\Tools\Loyalty;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * "Bring a friend": a customer's referral code, who referred whom, and the reward in points (docs/LOYALTY.md has the anti-abuse table).
 *
 * Code    `Customer.referralCode`: 7 characters of A-Z 2-9 without look-alikes (no I L O 0 1), unique, minted lazily by `codeFor()`.
 * Link    `Customer.referredBy` (+ `referredAt`): set ONCE, atomically (`UPDATE ... WHERE referred_by_id IS NULL`), by the server only.
 *         The code text travels on the ORDER (`WashOrder.referralCode`) from the staff intake, the public booking form or the chat; it is
 *         resolved (`attach`) when the order leaves Reserved, so a booking never touches a customer before the car arrives.
 * Reward  when the referred customer's FIRST order is Completed and its paid total is >= loyaltyReferralMinAmountEur:
 *         referrer +loyaltyReferrerPoints, referred +loyaltyReferredPoints (two Referral ledger rows with unique keys per customer and
 *         generation - a replay, a reopen-and-complete or a parallel request cannot pay twice). loyaltyReferralHoldDays > 0 keeps the reward
 *         Pending (no points yet) and the daily job releases it; leaving Completed in that window cancels it. With 0 the points are paid at once and
 *         are taken back (Reverse rows, only what is still there) if that order later leaves Completed.
 * Reasons (stable codes, `Loyalty` i18n `referralReasons`): unknown, off, self, has_completed, already_referred, loop, cap, vehicle.
 * A PUBLIC caller only ever learns ok / invalid (`publicOk`).
 */
class Referral
{
    public const REASONS = ['unknown', 'off', 'self', 'has_completed', 'already_referred', 'loop', 'cap', 'vehicle'];
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const LEN = 7;

    public function __construct(
        private EntityManager $em,
        private LoyaltyConfig $cfg,
        private Points $points,
        private Log $log
    ) {}

    private function sql(string $statement, array $params = []): \PDOStatement
    {
        $st = $this->em->getPDO()->prepare($statement);
        $st->execute($params);

        return $st;
    }

    // ------------------------------------------------------------------ codes

    /** Upper case, only the characters a code can contain (spaces, dashes and look-alike digits dropped). */
    public static function normalize(string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code))) ?? '';
    }

    public static function looksValid(string $code): bool
    {
        return (bool) preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{6,8}$/', $code);
    }

    /** The customer's own code; minted (atomically, retrying a clash) the first time it is asked for. */
    public function codeFor(string $customerId): ?string
    {
        $st = $this->sql('SELECT referral_code FROM customer WHERE id = ? AND deleted = 0', [$customerId]);
        $row = $st->fetch(\PDO::FETCH_NUM);

        if (!$row) {
            return null;
        }

        if ($row[0]) {
            return (string) $row[0];
        }

        for ($try = 0; $try < 12; $try++) {
            $code = '';

            for ($i = 0; $i < self::LEN; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            try {
                $n = $this->sql('UPDATE customer SET referral_code = ? WHERE id = ? AND deleted = 0 AND referral_code IS NULL', [$code, $customerId])->rowCount();
            } catch (\PDOException $e) {
                continue; // the code is taken: another one
            }

            if ($n > 0) {
                return $code;
            }

            // somebody else minted it between our read and write
            $again = $this->sql('SELECT referral_code FROM customer WHERE id = ?', [$customerId])->fetchColumn();

            if ($again) {
                return (string) $again;
            }
        }

        return null;
    }

    public function findByCode(string $code): ?Entity
    {
        $code = self::normalize($code);

        if (!self::looksValid($code)) {
            return null;
        }

        return $this->em->getRDBRepository('Customer')->where(['referralCode' => $code, 'deleted' => false])->findOne();
    }

    public function enabled(): bool
    {
        return $this->cfg->referralOn();
    }

    /** The PUBLIC answer: is this a working code? (yes / no only; the programme must be on) */
    public function publicOk(string $code): bool
    {
        return $this->cfg->referralOn() && $this->findByCode($code) !== null;
    }

    // ------------------------------------------------------------------ the rules

    private function digits8(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return strlen($d) >= 6 ? substr($d, -8) : '';
    }

    private function hasCompleted(string $customerId, ?string $exceptOrderId = null): bool
    {
        $q = "SELECT COUNT(*) FROM wash_order WHERE customer_id = :c AND deleted = 0 AND status = 'Completed'";
        $p = ['c' => $customerId];

        if ($exceptOrderId) {
            $q .= ' AND id <> :o';
            $p['o'] = $exceptOrderId;
        }

        return (int) $this->sql($q, $p)->fetchColumn() > 0;
    }

    /**
     * Why this code cannot be given to this customer (null = it can). The customer may not exist yet (new customer at the intake):
     * then $ctx carries what we know: phone, email, plates (list of normalised plates), vehicleCustomerId.
     *
     * @param array{phone?: ?string, email?: ?string, plates?: list<string>, vehicleCustomerId?: ?string} $ctx
     */
    public function problem(?Entity $referrer, ?Entity $referred, array $ctx = [], ?string $exceptOrderId = null): ?string
    {
        if (!$this->cfg->referralOn()) {
            return 'off';
        }

        if (!$referrer || $referrer->get('deleted')) {
            return 'unknown';
        }

        $rid = $referrer->getId();
        $cid = $referred ? $referred->getId() : null;

        // not self: the same record, the same phone (last 8 digits), the same e-mail, or the car of the referrer
        $phone = $this->digits8($referred ? $referred->get('phone') : ($ctx['phone'] ?? null));
        $email = strtolower(trim((string) ($referred ? $referred->get('email') : ($ctx['email'] ?? ''))));

        if ($cid === $rid) {
            return 'self';
        }

        if ($phone !== '' && $phone === $this->digits8($referrer->get('phone'))) {
            return 'self';
        }

        if ($email !== '' && $email === strtolower(trim((string) $referrer->get('email')))) {
            return 'self';
        }

        $plates = array_values(array_filter(array_map('strval', $ctx['plates'] ?? [])));

        if (($ctx['vehicleCustomerId'] ?? null) === $rid) {
            return 'vehicle';
        }

        if ($plates) {
            $in = implode(',', array_fill(0, count($plates), '?'));
            $n = (int) $this->sql(
                "SELECT COUNT(*) FROM vehicle WHERE customer_id = ? AND deleted = 0 AND name IN ($in)",
                array_merge([$rid], $plates)
            )->fetchColumn();

            if ($n > 0) {
                return 'vehicle';
            }
        }

        if ($referred) {
            // only a customer who never completed an order can be "brought in"
            if ($this->hasCompleted($cid, $exceptOrderId)) {
                return 'has_completed';
            }

            // one referrer, ever
            if ($referred->get('referredById')) {
                return 'already_referred';
            }

            // no loops: the referrer must not (transitively) have been referred by the customer
            $cur = $rid;

            for ($depth = 0; $depth < 25 && $cur; $depth++) {
                if ($cur === $cid) {
                    return 'loop';
                }

                $cur = $this->sql('SELECT referred_by_id FROM customer WHERE id = ?', [$cur])->fetchColumn() ?: null;
            }
        }

        // a referrer cannot bring more than N new customers per rolling 30 days
        $cap = $this->cfg->referralMaxPerMonth();

        if ($cap > 0) {
            $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
            $n = (int) $this->sql(
                'SELECT COUNT(*) FROM customer WHERE referred_by_id = ? AND deleted = 0 AND referred_at >= ?',
                [$rid, $since]
            )->fetchColumn();

            if ($n >= $cap) {
                return 'cap';
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ attach (the order carries the text)

    /**
     * Resolve the code on an order that is no longer Reserved: link the customer to the referrer, once. The outcome is written on the order
     * (`referralNote`: 'applied' or a reason). Never throws.
     */
    public function resolveOrder(Entity $order): void
    {
        try {
            $code = Referral::normalize((string) $order->get('referralCode'));

            if ($code === '' || $order->get('referralResolved') || !$order->get('customerId')) {
                return;
            }

            // claim: the first caller resolves, a repeat does nothing
            if ($this->sql('UPDATE wash_order SET referral_resolved = 1 WHERE id = ? AND referral_resolved = 0', [$order->getId()])->rowCount() < 1) {
                return;
            }

            $note = $this->attach($code, (string) $order->get('customerId'), $order->getId(), (string) $order->get('vehicleId'));
            $this->sql('UPDATE wash_order SET referral_note = ? WHERE id = ?', [$note, $order->getId()]);
            $order->set('referralNote', $note);
        } catch (\Throwable $e) {
            $this->log->warning('Referral resolve failed for order ' . $order->getId() . ': ' . $e->getMessage());
        }
    }

    /** @return string 'applied' or the reason it was not */
    private function attach(string $code, string $customerId, string $orderId, string $vehicleId): string
    {
        $referrer = $this->findByCode($code);
        $customer = $this->em->getEntityById('Customer', $customerId);

        if (!$customer) {
            return 'unknown';
        }

        $pdo = $this->em->getPDO();
        $lock = 'cw_referral_' . ($referrer ? $referrer->getId() : 'none');
        $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 5)");

        try {
            $ctx = ['plates' => [], 'vehicleCustomerId' => null];

            if ($vehicleId !== '') {
                $v = $this->em->getEntityById('Vehicle', $vehicleId);
                $ctx['vehicleCustomerId'] = $v ? $v->get('customerId') : null;
            }

            $why = $this->problem($referrer, $customer, $ctx, $orderId);

            if ($why !== null) {
                return $why;
            }

            $n = $this->sql(
                'UPDATE customer SET referred_by_id = ?, referred_at = ? WHERE id = ? AND deleted = 0 AND referred_by_id IS NULL',
                [$referrer->getId(), gmdate('Y-m-d H:i:s'), $customerId]
            )->rowCount();

            return $n > 0 ? 'applied' : 'already_referred';
        } finally {
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")");
        }
    }

    // ------------------------------------------------------------------ the reward

    /** The order was Completed. Pay (or park as Pending) the reward when it is the referred customer's first and big enough. Never throws. */
    public function onCompleted(Entity $order): void
    {
        try {
            if (!$this->cfg->referralOn() || !$order->get('customerId')) {
                return;
            }

            $cid = (string) $order->get('customerId');
            $row = $this->sql('SELECT referred_by_id, referral_state FROM customer WHERE id = ? AND deleted = 0', [$cid])->fetch(\PDO::FETCH_ASSOC);

            if (!$row || !$row['referred_by_id'] || !in_array((string) $row['referral_state'], ['', 'Reversed'], true)) {
                return;
            }

            $paid = round((float) $order->get('finalPrice'), 2);

            if ($paid <= 0.004 || $paid + 0.004 < $this->cfg->referralMinAmount() || $this->hasCompleted($cid, $order->getId())) {
                return;
            }

            $hold = $this->cfg->referralHoldDays();

            if ($hold > 0) {
                $this->sql(
                    "UPDATE customer SET referral_state = 'Pending', referral_order_id = ?, referral_due_at = ? WHERE id = ? AND COALESCE(referral_state, '') IN ('', 'Reversed')",
                    [$order->getId(), gmdate('Y-m-d H:i:s', time() + $hold * 86400), $cid]
                );

                return;
            }

            $this->pay($cid, $order->getId(), true);
        } catch (\Throwable $e) {
            $this->log->warning('Referral reward failed for order ' . $order->getId() . ': ' . $e->getMessage());
        }
    }

    /**
     * Claim and pay. $keepOrder = keep the order id on the customer (reversible); the job releases a held reward with false (final).
     */
    private function pay(string $cid, string $orderId, bool $keepOrder): void
    {
        $claim = $this->sql(
            "UPDATE customer SET referral_state = 'Paid', referral_order_id = ?, referral_due_at = NULL WHERE id = ? AND COALESCE(referral_state, '') IN ('', 'Reversed', 'Pending') AND deleted = 0",
            [$keepOrder ? $orderId : null, $cid]
        );

        if ($claim->rowCount() < 1) {
            return;
        }

        $row = $this->sql('SELECT referred_by_id, referral_gen FROM customer WHERE id = ?', [$cid])->fetch(\PDO::FETCH_ASSOC);
        $gen = (int) $row['referral_gen'];
        $referrer = (string) $row['referred_by_id'];

        try {
            if ($this->cfg->referrerPoints() > 0) {
                $this->points->move($referrer, 'Referral', $this->cfg->referrerPoints(), [
                    'orderId' => $orderId, 'note' => 'referral', 'uniqueKey' => 'ref:' . $cid . ':' . $gen,
                ]);
            }

            if ($this->cfg->referredPoints() > 0) {
                $this->points->move($cid, 'Referral', $this->cfg->referredPoints(), [
                    'orderId' => $orderId, 'note' => 'referral-bonus', 'uniqueKey' => 'refd:' . $cid . ':' . $gen,
                ]);
            }
        } catch (\Throwable $e) {
            // a duplicate key = this generation was already paid (a replay): nothing to undo. Anything else: give the claim back.
            if (!str_contains($e->getMessage(), 'Duplicate') && !str_contains($e->getMessage(), '1062')) {
                $this->sql("UPDATE customer SET referral_state = '' WHERE id = ? AND referral_state = 'Paid'", [$cid]);
                $this->log->warning('Referral payment failed for customer ' . $cid . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * The order left Completed (re-opened, cancelled) or was deleted: cancel a Pending reward, or take back a paid one
     * (only what is still there). Never throws.
     */
    public function onLeftCompleted(string $orderId): void
    {
        try {
            $row = $this->sql(
                "SELECT id, referred_by_id, referral_state, referral_gen FROM customer WHERE referral_order_id = ? AND referral_state IN ('Pending', 'Paid') AND deleted = 0",
                [$orderId]
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return;
            }

            $cid = (string) $row['id'];
            $was = (string) $row['referral_state'];
            $gen = (int) $row['referral_gen'];

            $n = $this->sql(
                "UPDATE customer SET referral_state = 'Reversed', referral_gen = COALESCE(referral_gen, 0) + 1, referral_due_at = NULL WHERE id = ? AND referral_state = ?",
                [$cid, $was]
            )->rowCount();

            if ($n < 1 || $was === 'Pending') {
                return;
            }

            foreach ([['ref:', (string) $row['referred_by_id'], 'referral'], ['refd:', $cid, 'referral-bonus']] as [$prefix, $holder, $note]) {
                $lot = $this->sql('SELECT id, points FROM loyalty_ledger WHERE unique_key = ? AND deleted = 0', [$prefix . $cid . ':' . $gen])->fetch(\PDO::FETCH_ASSOC);

                if ($lot && (int) $lot['points'] > 0) {
                    $this->points->move($holder, 'Reverse', -(int) $lot['points'], ['orderId' => $orderId, 'lotId' => $lot['id'], 'note' => $note]);
                }
            }
        } catch (\Throwable $e) {
            $this->log->warning('Referral reversal failed for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    /**
     * The daily job: pay every Pending reward whose hold has passed (the order must still be Completed).
     *
     * @return int rewards released
     */
    public function releaseDue(bool $dry = false): int
    {
        $rows = $this->sql(
            "SELECT c.id, c.referral_order_id FROM customer c WHERE c.deleted = 0 AND c.referral_state = 'Pending' AND c.referral_due_at <= ?",
            [gmdate('Y-m-d H:i:s')]
        )->fetchAll(\PDO::FETCH_ASSOC);
        $n = 0;

        foreach ($rows as $r) {
            $ok = (int) $this->sql(
                "SELECT COUNT(*) FROM wash_order WHERE id = ? AND deleted = 0 AND status = 'Completed'",
                [$r['referral_order_id']]
            )->fetchColumn();

            if (!$ok) {
                if (!$dry) {
                    $this->sql("UPDATE customer SET referral_state = 'Reversed', referral_gen = COALESCE(referral_gen, 0) + 1, referral_due_at = NULL WHERE id = ? AND referral_state = 'Pending'", [$r['id']]);
                }

                continue;
            }

            if (!$dry) {
                $this->pay((string) $r['id'], (string) $r['referral_order_id'], false);
            }

            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------------ for the customer panel

    /** @return array{code: ?string, referredBy: ?array{id: string, name: string}, referredCount: int, rewardedCount: int, rewardPoints: int, state: string} */
    public function summary(Entity $customer): array
    {
        $id = $customer->getId();
        $by = null;

        if ($customer->get('referredById')) {
            $r = $this->em->getEntityById('Customer', (string) $customer->get('referredById'));
            $by = $r ? ['id' => $r->getId(), 'name' => (string) $r->get('name')] : null;
        }

        $count = (int) $this->sql('SELECT COUNT(*) FROM customer WHERE referred_by_id = ? AND deleted = 0', [$id])->fetchColumn();
        $rewarded = (int) $this->sql("SELECT COUNT(*) FROM customer WHERE referred_by_id = ? AND deleted = 0 AND referral_state = 'Paid'", [$id])->fetchColumn();
        $pts = (int) $this->sql(
            "SELECT COALESCE(SUM(points), 0) FROM loyalty_ledger WHERE customer_id = ? AND deleted = 0 AND (unique_key LIKE 'ref:%' OR (kind = 'Reverse' AND note = 'referral'))",
            [$id]
        )->fetchColumn();

        return [
            'code' => $this->cfg->referralOn() ? $this->codeFor($id) : ($customer->get('referralCode') ?: null),
            'referredBy' => $by,
            'referredCount' => $count,
            'rewardedCount' => $rewarded,
            'rewardPoints' => $pts,
            'state' => (string) $customer->get('referralState'),
        ];
    }
}
