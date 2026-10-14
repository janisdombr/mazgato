<?php

namespace Espo\Custom\Tools\Loyalty;

use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Licensing\Modules;

/**
 * The loyalty settings in one place (CLAUDE.md "Loyalty"). Stored in the config (Administration -> Car wash -> Loyalty,
 * `Loyalty/action/adminSave`), read here and nowhere else.
 *
 *   loyaltyFreeEvery     0 = off; otherwise N >= 2: every N-th wash is free
 *   loyaltyFreeScope     Base (the base-wash line becomes 0, add-ons stay paid) | WholeOrder
 *   loyaltyFreeMinPrice  a wash cheaper than this neither counts nor can be the free one
 *
 * Points (phase 2):
 *   loyaltyEarnPerEur          points per EUR paid (default 1 while the module is on; 0 = points are off altogether)
 *   loyaltyPointValueEur       what one point is worth when redeemed (0.01)
 *   loyaltyRedeemMinPoints     the smallest redemption (0 = any)
 *   loyaltyRedeemMaxPercent    at most this share of the order (after a promo / free wash) can be paid with points (50)
 *   loyaltyEarnOnPromo         does an order that used a promo code earn points (yes)
 *   loyaltyPointsExpireDays    a lot of points lives this many days (0 = never)
 * Referral (rewards are paid in points, so it needs the points scheme on):
 *   loyaltyReferrerPoints 100, loyaltyReferredPoints 50, loyaltyReferralMinAmountEur 5, loyaltyReferralHoldDays 0,
 *   loyaltyReferralMaxPerMonth 10 (per referrer, rolling 30 days)
 */
class LoyaltyConfig
{
    public const SCOPES = ['Base', 'WholeOrder'];

    public function __construct(private Config $config, private Modules $modules)
    {}

    public function enabled(): bool
    {
        return $this->modules->moduleEnabled(Modules::LOYALTY);
    }

    /** 0 = the free-wash scheme is off. */
    public function freeEvery(): int
    {
        $n = (int) $this->config->get('loyaltyFreeEvery', 0);

        return $n >= 2 ? min($n, 1000) : 0;
    }

    public function freeScope(): string
    {
        $s = (string) $this->config->get('loyaltyFreeScope', 'Base');

        return in_array($s, self::SCOPES, true) ? $s : 'Base';
    }

    public function freeMinPrice(): float
    {
        return max(0.0, (float) $this->config->get('loyaltyFreeMinPrice', 0));
    }

    /** Today's date in the installation's time zone (the promo period is in LOCAL days). */
    public function today(): string
    {
        $tz = new \DateTimeZone((string) $this->config->get('timeZone', 'UTC') ?: 'UTC');

        return (new \DateTime('now', $tz))->format('Y-m-d');
    }

    // ------------------------------------------------------------------ points

    /** Points earned per EUR paid; 0 = the points scheme is off. */
    public function earnPerEur(): float
    {
        return max(0.0, min(1000.0, (float) $this->config->get('loyaltyEarnPerEur', 1)));
    }

    public function pointsOn(): bool
    {
        return $this->enabled() && $this->earnPerEur() > 0;
    }

    public function pointValue(): float
    {
        $v = (float) $this->config->get('loyaltyPointValueEur', 0.01);

        return $v > 0 ? min($v, 1000.0) : 0.01;
    }

    public function redeemMin(): int
    {
        return max(0, (int) $this->config->get('loyaltyRedeemMinPoints', 0));
    }

    public function redeemMaxPercent(): float
    {
        return max(0.0, min(100.0, (float) $this->config->get('loyaltyRedeemMaxPercent', 50)));
    }

    public function earnOnPromo(): bool
    {
        return (bool) $this->config->get('loyaltyEarnOnPromo', true);
    }

    public function expireDays(): int
    {
        return max(0, min(3650, (int) $this->config->get('loyaltyPointsExpireDays', 0)));
    }

    public function referrerPoints(): int
    {
        return max(0, (int) $this->config->get('loyaltyReferrerPoints', 100));
    }

    public function referredPoints(): int
    {
        return max(0, (int) $this->config->get('loyaltyReferredPoints', 50));
    }

    public function referralMinAmount(): float
    {
        return max(0.0, (float) $this->config->get('loyaltyReferralMinAmountEur', 5));
    }

    public function referralHoldDays(): int
    {
        return max(0, min(365, (int) $this->config->get('loyaltyReferralHoldDays', 0)));
    }

    public function referralMaxPerMonth(): int
    {
        return max(0, (int) $this->config->get('loyaltyReferralMaxPerMonth', 10));
    }

    /** The referral programme is live: points on and at least one of the two rewards is worth something. */
    public function referralOn(): bool
    {
        return $this->pointsOn() && ($this->referrerPoints() > 0 || $this->referredPoints() > 0);
    }
}
