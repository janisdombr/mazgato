/**
 * Administration → Car wash → Loyalty: "every N-th wash is free" (how many, what is free, the smallest wash that counts), the points
 * (earn rate, value, limits, expiry), the referral reward and a link to the promo code list. Saved with Loyalty/action/adminSave; the module switch itself is Settings → Enabled modules.
 */
define(['custom:views/admin/settings-page', 'custom:lib/state'], function (Dep, State) {

    var esc = State.esc;

    return Dep.extend({

        scope: 'Loyalty',

        api: 'Loyalty/action/',

        groups: [
            {title: 'free', fields: [
                {key: 'loyaltyFreeEvery', type: 'int', placeholder: '10'},
                {key: 'loyaltyFreeScope', type: 'select', options: ['Base', 'WholeOrder']},
                {key: 'loyaltyFreeMinPrice', type: 'text', placeholder: '0'},
            ]},
            {title: 'points', fields: [
                {key: 'loyaltyEarnPerEur', type: 'text', placeholder: '1'},
                {key: 'loyaltyPointValueEur', type: 'text', placeholder: '0.01'},
                {key: 'loyaltyRedeemMinPoints', type: 'int', placeholder: '0'},
                {key: 'loyaltyRedeemMaxPercent', type: 'text', placeholder: '50'},
                {key: 'loyaltyEarnOnPromo', type: 'bool'},
                {key: 'loyaltyPointsExpireDays', type: 'int', placeholder: '0'},
            ]},
            {title: 'referral', fields: [
                {key: 'loyaltyReferrerPoints', type: 'int', placeholder: '100'},
                {key: 'loyaltyReferredPoints', type: 'int', placeholder: '50'},
                {key: 'loyaltyReferralMinAmountEur', type: 'text', placeholder: '5'},
                {key: 'loyaltyReferralHoldDays', type: 'int', placeholder: '0'},
                {key: 'loyaltyReferralMaxPerMonth', type: 'int', placeholder: '10'},
            ]},
        ],

        extraTop: function () {
            return '<section class="cw-section"><p><a href="#PromoCode">' + esc(this.L('promoLink')) + '</a></p>' +
                (this.data.modules.loyalty ? '' : '<p class="cw-meta">' + esc(this.L('moduleOff')) + '</p>') + '</section>';
        },
    });
});
