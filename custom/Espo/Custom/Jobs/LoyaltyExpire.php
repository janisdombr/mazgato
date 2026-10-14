<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Loyalty\LoyaltyConfig;
use Espo\Custom\Tools\Loyalty\Points;
use Espo\Custom\Tools\Loyalty\Referral;

/**
 * Daily loyalty upkeep (module loyalty, docs/LOYALTY.md): points past their expiry date are written off (one Expire row per lot) and referral
 * rewards whose hold period has passed are paid. Also `bin/command loyalty-expire [--dry-run]`.
 */
class LoyaltyExpire implements JobDataLess
{
    public function __construct(private Points $points, private Referral $referral, private LoyaltyConfig $cfg)
    {}

    public function run(): void
    {
        if (!$this->cfg->enabled()) {
            return;
        }

        $this->points->expireAll();
        $this->referral->releaseDue();
    }
}
