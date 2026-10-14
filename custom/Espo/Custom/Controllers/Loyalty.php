<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Custom\Tools\Loyalty\FreeWash;
use Espo\Custom\Tools\Loyalty\Loyalty as LoyaltyTool;
use Espo\Custom\Tools\Loyalty\LoyaltyConfig;
use Espo\Custom\Tools\Loyalty\Points;
use Espo\Custom\Tools\Loyalty\Promo;
use Espo\Custom\Tools\Loyalty\Referral;
use Espo\Custom\Tools\Validation\Numbers;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Loyalty/action/*  (module `loyalty`, docs/LOYALTY.md). Staff only - the public booking form and the customer chat reach the promo
 * code through the booking code (`Tools/Loyalty/Promo::publicOk`), never through here.
 *
 *   status      { }                                       -> { enabled, free: {every, scope, minPrice}, promos }   (read on orders)
 *   checkPromo  { code?, customerId?, amount, baseAmount?, skipLoyalty? }
 *                                                        -> the live preview of the intake form (create on orders)
 *   customer    { customerId }                            -> the customer's counters (read on the customer and on orders)
 *   adjust      { customerId, washes }                    -> administrator: set the counted-washes counter
 *   adjustPoints { customerId, points, reason }           -> administrator or edit-all Customer: +/- points (a reason is required)
 *   checkReferral { code, customerId?, phone?, email? }   -> create on orders: would this code be accepted for this customer?
 *   adminGet / adminSave                                  -> administrator: the settings page (also while the module is off)
 *
 * A module that is off answers 403 `module_disabled` (status answers `enabled:false`; the admin page still opens so it can be switched on).
 * Every action begins with its ACL check; a tablet key opens nothing here (DeviceGuard).
 */
class Loyalty
{
    public function __construct(
        private EntityManager $em,
        private User $user,
        private Acl $acl,
        private Config $config,
        private ConfigWriter $writer,
        private Modules $modules,
        private LoyaltyConfig $cfg,
        private LoyaltyTool $loyalty,
        private FreeWash $free,
        private Promo $promo,
        private Points $points,
        private Referral $referral,
        DeviceGuard $deviceGuard
    ) {
        $deviceGuard->denyDevice($user);
    }

    private function requireModule(): void
    {
        if (!$this->modules->moduleEnabled(Modules::LOYALTY)) {
            throw new Forbidden('module_disabled');
        }
    }

    private function requireAdmin(): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }
    }

    private function str(?stdClass $d, string $k, int $max = 200): string
    {
        return mb_substr(trim((string) ($d->$k ?? '')), 0, $max);
    }

    public function postActionStatus(Request $request): stdClass
    {
        if (!$this->acl->checkScope('WashOrder', 'read')) {
            throw new Forbidden();
        }

        if (!$this->modules->moduleEnabled(Modules::LOYALTY)) {
            return (object) ['enabled' => false];
        }

        return (object) [
            'enabled' => true,
            'free' => (object) ['every' => $this->cfg->freeEvery(), 'scope' => $this->cfg->freeScope(), 'minPrice' => $this->cfg->freeMinPrice()],
            // the intake form shows its promo field only when a code could work (no code is revealed)
            'promos' => $this->promo->anyPublic(),
            'points' => (object) [
                'enabled' => $this->cfg->pointsOn(),
                'pointValue' => $this->cfg->pointValue(),
                'minPoints' => $this->cfg->redeemMin(),
                'maxPercent' => $this->cfg->redeemMaxPercent(),
            ],
            'referral' => $this->cfg->referralOn(),
        ];
    }

    public function postActionCheckPromo(Request $request): stdClass
    {
        if (!$this->acl->checkScope('WashOrder', 'create')) {
            throw new Forbidden();
        }

        $this->requireModule();

        $d = $request->getParsedBody() ?: new stdClass();
        $customerId = $this->str($d, 'customerId', 40) ?: null;

        if ($customerId) {
            $customer = $this->em->getEntityById('Customer', $customerId);

            if (!$customer) {
                throw new NotFound('customer_not_found');
            }

            if (!$this->acl->checkEntityRead($customer)) {
                throw new Forbidden();
            }
        }

        Numbers::assertNumber('amount', $d->amount ?? null);
        $base = null;

        if (isset($d->baseAmount) && $d->baseAmount !== '' && $d->baseAmount !== null) {
            Numbers::assertNumber('baseAmount', $d->baseAmount);
            $base = (float) $d->baseAmount;
        }

        $redeem = 0;

        if (isset($d->redeemPoints) && $d->redeemPoints !== '' && $d->redeemPoints !== null) {
            Numbers::assertNumber('redeemPoints', $d->redeemPoints, 0.0, 100000000.0);
            $redeem = (int) $d->redeemPoints;
        }

        $out = $this->loyalty->preview($this->str($d, 'code', 60), $customerId, (float) $d->amount, $base, !empty($d->skipLoyalty), $redeem);
        $out['ok'] = true;

        return (object) $out;
    }

    public function postActionCustomer(Request $request): stdClass
    {
        if (!$this->acl->checkScope('Customer', 'read') || !$this->acl->checkScope('WashOrder', 'read')) {
            throw new Forbidden();
        }

        $this->requireModule();

        $c = $this->em->getEntityById('Customer', $this->str($request->getParsedBody(), 'customerId', 40));

        if (!$c) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityRead($c)) {
            throw new Forbidden();
        }

        $out = $this->free->info($c);
        $out['pointsOn'] = $this->cfg->pointsOn();
        $out['referralOn'] = $this->cfg->referralOn();
        $out['canAdjust'] = $this->canAdjustPoints();

        if ($this->cfg->pointsOn()) {
            $id = $c->getId();
            $balance = $this->points->balance($id);
            $out['points'] = [
                'balance' => $balance,
                'value' => $this->points->valueOf($balance),
                'spendable' => $this->points->spendable($id),
                'nextExpiry' => $this->points->nextExpiry($id),
            ];
        }

        if ($this->cfg->referralOn() || $c->get('referredById')) {
            $out['referral'] = $this->referral->summary($c);
        }

        // the journal is its own scope: shown only to a role that may read it (all records)
        if ($this->acl->getLevel('LoyaltyLedger', 'read') === 'all') {
            $st = $this->em->getPDO()->prepare(
                'SELECT id, kind, points, balance_after AS balanceAfter, note, wash_order_id AS washOrderId, created_at AS createdAt, expires_at AS expiresAt ' .
                'FROM loyalty_ledger WHERE customer_id = ? AND deleted = 0 ORDER BY seq DESC LIMIT 50'
            );
            $st->execute([$c->getId()]);
            $out['ledger'] = $st->fetchAll(\PDO::FETCH_ASSOC);
        }

        return (object) $out;
    }

    private function canAdjustPoints(): bool
    {
        return $this->user->isAdmin() || $this->acl->getLevel('Customer', 'edit') === 'all';
    }

    /** +/- points with a reason, by an administrator or a role that may edit every customer. A negative balance is refused. */
    public function postActionAdjustPoints(Request $request): stdClass
    {
        if (!$this->canAdjustPoints()) {
            throw new Forbidden();
        }

        $this->requireModule();

        $d = $request->getParsedBody() ?: new stdClass();
        $c = $this->em->getEntityById('Customer', $this->str($d, 'customerId', 40));

        if (!$c) {
            throw new NotFound();
        }

        if (!$this->cfg->pointsOn()) {
            throw new BadRequest('points_off');
        }

        $reason = $this->str($d, 'reason', 250);

        if (mb_strlen($reason) < 3) {
            throw new BadRequest('reason_required');
        }

        Numbers::assertNumber('points', $d->points ?? null, -1000000.0, 1000000.0);

        if ((float) $d->points != (int) $d->points || (int) $d->points === 0) {
            throw new BadRequest("invalid_number: 'points' must be a whole number other than 0.");
        }

        try {
            $r = $this->points->move($c->getId(), 'Adjust', (int) $d->points, ['note' => $reason]);
        } catch (BadRequest $e) {
            throw $e;
        }

        return (object) ['ok' => true, 'balance' => $r['after'], 'delta' => $r['delta']];
    }

    /** Staff side: would this referral code be accepted for this customer (or a new one with this phone / e-mail)? Detailed reasons. */
    public function postActionCheckReferral(Request $request): stdClass
    {
        if (!$this->acl->checkScope('WashOrder', 'create')) {
            throw new Forbidden();
        }

        $this->requireModule();

        $d = $request->getParsedBody() ?: new stdClass();
        $customer = null;

        if ($this->str($d, 'customerId', 40) !== '') {
            $customer = $this->em->getEntityById('Customer', $this->str($d, 'customerId', 40));

            if (!$customer) {
                throw new NotFound('customer_not_found');
            }

            if (!$this->acl->checkEntityRead($customer)) {
                throw new Forbidden();
            }
        }

        $referrer = $this->referral->findByCode($this->str($d, 'code', 20));
        $plate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->str($d, 'plate', 20)) ?? '');
        $why = $this->referral->problem($referrer, $customer, [
            'phone' => $this->str($d, 'phone', 30),
            'email' => $this->str($d, 'email', 100),
            'plates' => $plate !== '' ? [$plate] : [],
        ]);

        return (object) ['ok' => $why === null, 'reason' => $why, 'on' => $this->cfg->referralOn()];
    }

    public function postActionAdjust(Request $request): stdClass
    {
        $this->requireAdmin();
        $this->requireModule();

        $d = $request->getParsedBody() ?: new stdClass();
        $c = $this->em->getEntityById('Customer', $this->str($d, 'customerId', 40));

        if (!$c) {
            throw new NotFound();
        }

        $every = $this->cfg->freeEvery();

        if ($every < 2) {
            throw new BadRequest('loyalty_free_off');
        }

        Numbers::assertNumber('washes', $d->washes ?? null, 0.0, (float) ($every - 1));
        $w = (int) $d->washes;

        $st = $this->em->getPDO()->prepare('UPDATE customer SET loyalty_washes = :w WHERE id = :id');
        $st->execute(['w' => $w, 'id' => $c->getId()]);

        return (object) (['ok' => true] + $this->free->info($this->em->getEntityById('Customer', $c->getId())));
    }

    // ------------------------------------------------------------------ settings page (administrator)

    public function postActionAdminGet(): stdClass
    {
        $this->requireAdmin();

        return (object) [
            'values' => (object) [
                'loyaltyFreeEvery' => (int) $this->config->get('loyaltyFreeEvery', 0),
                'loyaltyFreeScope' => $this->cfg->freeScope(),
                'loyaltyFreeMinPrice' => $this->cfg->freeMinPrice(),
                'loyaltyEarnPerEur' => $this->cfg->earnPerEur(),
                'loyaltyPointValueEur' => $this->cfg->pointValue(),
                'loyaltyRedeemMinPoints' => $this->cfg->redeemMin(),
                'loyaltyRedeemMaxPercent' => $this->cfg->redeemMaxPercent(),
                'loyaltyEarnOnPromo' => $this->cfg->earnOnPromo(),
                'loyaltyPointsExpireDays' => $this->cfg->expireDays(),
                'loyaltyReferrerPoints' => $this->cfg->referrerPoints(),
                'loyaltyReferredPoints' => $this->cfg->referredPoints(),
                'loyaltyReferralMinAmountEur' => $this->cfg->referralMinAmount(),
                'loyaltyReferralHoldDays' => $this->cfg->referralHoldDays(),
                'loyaltyReferralMaxPerMonth' => $this->cfg->referralMaxPerMonth(),
            ],
            'secrets' => (object) [],
            'modules' => ['loyalty' => $this->modules->moduleEnabled(Modules::LOYALTY)],
            'siteUrl' => (string) $this->config->get('siteUrl', ''),
        ];
    }

    public function postActionAdminSave(Request $request): stdClass
    {
        $this->requireAdmin();

        $v = ($request->getParsedBody()->values ?? null) ?: new stdClass();
        $set = [];

        if (isset($v->loyaltyFreeEvery)) {
            $raw = $v->loyaltyFreeEvery;
            $n = ($raw === '' || $raw === null) ? 0 : $raw;
            Numbers::assertNumber('loyaltyFreeEvery', $n, 0.0, 1000.0);

            if ((int) $n === 1) {
                throw new BadRequest("invalid_number: 'loyaltyFreeEvery' must be 0 (off) or at least 2.");
            }

            $set['loyaltyFreeEvery'] = (int) $n;
        }

        if (isset($v->loyaltyFreeScope)) {
            if (!in_array((string) $v->loyaltyFreeScope, LoyaltyConfig::SCOPES, true)) {
                throw new BadRequest("invalid_value: 'loyaltyFreeScope'.");
            }

            $set['loyaltyFreeScope'] = (string) $v->loyaltyFreeScope;
        }

        if (isset($v->loyaltyFreeMinPrice)) {
            $m = $v->loyaltyFreeMinPrice === '' ? 0 : $v->loyaltyFreeMinPrice;
            Numbers::assertNumber('loyaltyFreeMinPrice', $m, 0.0, 100000.0);
            $set['loyaltyFreeMinPrice'] = round((float) $m, 2);
        }

        // points and referral: [key => [min, max, whole number?, exclusive minimum?]]
        $nums = [
            'loyaltyEarnPerEur' => [0.0, 1000.0, false, false],
            'loyaltyPointValueEur' => [0.0, 1000.0, false, true],
            'loyaltyRedeemMinPoints' => [0.0, 100000000.0, true, false],
            'loyaltyRedeemMaxPercent' => [0.0, 100.0, false, false],
            'loyaltyPointsExpireDays' => [0.0, 3650.0, true, false],
            'loyaltyReferrerPoints' => [0.0, 1000000.0, true, false],
            'loyaltyReferredPoints' => [0.0, 1000000.0, true, false],
            'loyaltyReferralMinAmountEur' => [0.0, 100000.0, false, false],
            'loyaltyReferralHoldDays' => [0.0, 365.0, true, false],
            'loyaltyReferralMaxPerMonth' => [0.0, 1000.0, true, false],
        ];

        foreach ($nums as $key => [$min, $max, $whole, $excl]) {
            if (!isset($v->$key)) {
                continue;
            }

            $raw = $v->$key;

            if ($raw === '' || $raw === null) {
                continue;
            }

            Numbers::assertNumber($key, $raw, $min, $max, $excl);

            if ($whole && (float) $raw != (int) $raw) {
                throw new BadRequest("invalid_number: '$key' must be a whole number.");
            }

            $set[$key] = $whole ? (int) $raw : round((float) $raw, 4);
        }

        if (isset($v->loyaltyEarnOnPromo)) {
            $set['loyaltyEarnOnPromo'] = filter_var($v->loyaltyEarnOnPromo, FILTER_VALIDATE_BOOLEAN);
        }

        if ($set) {
            $this->writer->setMultiple($set);
            $this->writer->save();
        }

        return (object) ['ok' => true, 'saved' => array_keys($set)];
    }
}
