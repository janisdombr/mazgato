/**
 * Customer -> Loyalty (module `loyalty`), read-only; the numbers belong to the server (Tools/Loyalty/*):
 *   - the counted washes and the free-wash progress (when "every N-th wash free" is on),
 *   - the points: balance and its worth, the next expiry, the last 50 operations of the ledger (when the role may read it) and, for an
 *     administrator or a role that may edit every customer, the "Adjust points" button (a reason is required),
 *   - the referral: the customer's own code (Copy), who referred them, how many they brought and how many were rewarded.
 * The panel hides itself while the module is off or nothing in it is switched on.
 */
define(['views/record/panels/side', 'custom:lib/state', 'custom:lib/format'], function (Dep, State, Format) {

    return Dep.extend({

        templateContent: '<div class="cw-summary cw-kpis cw-kpis--col"></div>',

        events: {
            'click [data-action="cwAdjustPoints"]': function () { this.adjust(); },
            'click [data-action="cwCopyReferral"]': function (e) { this.copyCode($(e.currentTarget)); },
        },

        setup: function () {
            Dep.prototype.setup.call(this);
            this.listenTo(this.model, 'sync', function () { if (this.isRendered()) { this.load(); } });
        },

        afterRender: function () { this.load(); },

        t: function (n) { return this.translate(n, 'labels', 'Customer'); },

        tile: function (label, value, mod, sub) {
            return '<div class="cw-kpi' + (mod ? ' cw-kpi--' + mod : '') + '"><div class="cw-kpi__label">' + State.esc(label) +
                '</div><div class="cw-kpi__value">' + State.esc(value) + '</div>' + (sub ? '<div class="cw-meta">' + State.esc(sub) + '</div>' : '') + '</div>';
        },

        money: function (v) { return Format.fmtMoney ? Format.fmtMoney(v) : String(v); },

        day: function (utc) {
            if (!utc) { return ''; }
            return this.getDateTime().toDisplay(utc);
        },

        note: function (n) {
            if (!n) { return ''; }
            var tr = this.translate(n, 'ledgerNotes', 'Loyalty');

            return tr && tr !== n ? tr : n;
        },

        kind: function (k) {
            return this.getLanguage().translateOption(k, 'kind', 'LoyaltyLedger') || k;
        },

        load: function () {
            var $el = this.$el.find('.cw-summary');
            var $panel = this.$el.closest('.panel');

            Espo.Ajax.postRequest('Loyalty/action/customer', {customerId: this.model.id})
                .then(function (r) {
                    if (!r.enabled && !r.pointsOn && !r.referralOn) {
                        $panel.prop('hidden', true);

                        return;
                    }

                    this.data = r;
                    $panel.prop('hidden', false);
                    var h = '';

                    if (r.enabled) {
                        h += this.tile(this.t('loyaltyCount'), r.washes + ' / ' + (r.every - 1)) +
                            this.tile(this.t('loyaltyToNext'), r.freeAvailable ? this.t('loyaltyFreeNow') : String(r.toNext), r.freeAvailable ? 'good' : '') +
                            this.tile(this.t('loyaltyEarnedUsed'), r.earned + ' / ' + r.used) +
                            (r.held ? '<div class="cw-meta">' + State.esc(this.t('loyaltyFreeHeld')) + '</div>' : '');
                    }

                    if (r.points) {
                        var exp = r.points.nextExpiry || {};

                        h += this.tile(this.t('pointsBalance'), String(r.points.balance), r.points.balance > 0 ? 'good' : '', this.t('pointsWorth') + ' ' + this.money(r.points.value)) +
                            this.tile(this.t('pointsNextExpiry'), exp.at ? this.day(exp.at) : this.t('pointsNoExpiry'), '', exp.at ? exp.points + ' ' + this.t('pointsBalance').toLowerCase() : '');

                        if (r.canAdjust) {
                            h += '<div class="cw-panel-actions"><button type="button" class="btn btn-default btn-sm" data-action="cwAdjustPoints">' + State.esc(this.t('pointsAdjust')) + '</button></div>';
                        }
                    }

                    if (r.referral) {
                        var rf = r.referral;

                        h += (rf.code ? '<div class="cw-kpi"><div class="cw-kpi__label">' + State.esc(this.t('referralCode')) + '</div><div class="cw-kpi__value">' +
                                '<code class="cw-referral-code">' + State.esc(rf.code) + '</code> ' +
                                '<button type="button" class="btn btn-link btn-sm" data-action="cwCopyReferral" data-code="' + State.esc(rf.code) + '">' + State.esc(this.t('referralCopy')) + '</button></div></div>' : '') +
                            (rf.referredBy ? '<div class="cw-kpi"><div class="cw-kpi__label">' + State.esc(this.t('referredByLabel')) + '</div><div class="cw-kpi__value">' +
                                '<a href="#Customer/view/' + State.esc(rf.referredBy.id) + '">' + State.esc(rf.referredBy.name) + '</a></div></div>' : '') +
                            this.tile(this.t('referralCounts'), rf.referredCount + ' / ' + rf.rewardedCount) +
                            this.tile(this.t('referralPoints'), String(rf.rewardPoints)) +
                            (rf.state === 'Pending' ? '<div class="cw-meta">' + State.esc(this.t('referralWaiting')) + '</div>' : '');
                    }

                    if (r.ledger) {
                        var rows = r.ledger.map(function (e) {
                            return '<tr><td>' + State.esc(this.day(e.createdAt)) + '</td><td>' + State.esc(this.kind(e.kind)) + '</td>' +
                                '<td class="text-right"><b>' + (e.points > 0 ? '+' : '') + State.esc(e.points) + '</b></td><td class="text-right">' + State.esc(e.balanceAfter) + '</td>' +
                                '<td>' + State.esc(this.note(e.note)) + '</td></tr>';
                        }, this).join('');

                        h += '<details class="cw-table-toggle"><summary>' + State.esc(this.t('ledgerTitle')) + '</summary>' +
                            (rows ? '<table class="table table-condensed"><tbody>' + rows + '</tbody></table>' : '<div class="cw-meta">' + State.esc(this.t('ledgerNone')) + '</div>') +
                            '</details>';
                    }

                    $el.html(h);
                }.bind(this))
                .catch(function (xhr) {
                    if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
                    $panel.prop('hidden', true);
                }.bind(this));
        },

        adjust: function () {
            this.createView('adjustPoints', 'custom:views/customer/modals/adjust-points', {customerId: this.model.id}, function (view) {
                view.render();
                this.listenToOnce(view, 'done', function () { this.load(); });
            }.bind(this));
        },

        copyCode: function ($btn) {
            var code = String($btn.data('code') || '');

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(function () { Espo.Ui.success(this.t('referralCopied')); }.bind(this)).catch(function () {});
            } else {
                var ta = document.createElement('textarea');
                ta.value = code; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); Espo.Ui.success(this.t('referralCopied')); } catch (e) { /* the code is visible anyway */ }
                document.body.removeChild(ta);
            }
        },
    });
});
