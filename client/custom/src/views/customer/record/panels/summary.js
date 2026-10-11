/**
 * Customer summary (D27): visits, spent, last visit and what they still owe — the four
 * things the operator opens a customer card for, above the rows of empty fields.
 */
define(['views/record/panels/side', 'custom:lib/format', 'custom:lib/state'], function (Dep, Fmt, State) {

    return Dep.extend({

        templateContent: '<div class="cw-summary cw-kpis cw-kpis--col"></div>',

        setup: function () {
            Dep.prototype.setup.call(this);
            this.listenTo(this.model, 'sync', function () { if (this.isRendered()) { this.load(); } });
        },

        afterRender: function () {
            this.load();
        },

        t: function (n) { return this.translate(n, 'labels', 'Customer'); },

        tile: function (label, value, mod) {
            return '<div class="cw-kpi' + (mod ? ' cw-kpi--' + mod : '') + '"><div class="cw-kpi__label">' + State.esc(label) +
                '</div><div class="cw-kpi__value">' + State.esc(value) + '</div></div>';
        },

        // One tile per ACTIVE subscription: what is left ("7 / 10") or "unlimited", and until when.
        subscriptionTiles: function (list) {
            var T = function (k) { return this.translate(k, 'labels', 'Subscription'); }.bind(this);

            return list.filter(function (s) { return s.status === 'Active'; }).map(function (s) {
                var v = s.kind === 'Pack' ? (s.remaining + ' / ' + s.total) : T('unlimited');

                return this.tile(T('Subscription') + (s.validTo ? ' · ' + T('until') + ' ' + s.validTo : ''), v);
            }, this).join('');
        },

        load: function () {
            var $el = this.$el.find('.cw-summary');
            var lang = this.getPreferences().get('language') || this.getConfig().get('language');

            Espo.Ajax.postRequest('CarWash/action/customerSummary', {customerId: this.model.id})
                .then(function (r) {
                    var last = '';

                    if (r.lastVisit) {
                        try { last = this.getDateTime().toDisplayDate(r.lastVisit); } catch (e) { last = String(r.lastVisit).slice(0, 10); }
                    }

                    $el.html(
                        this.tile(this.t('summaryVisits'), String(r.visits)) +
                        this.tile(this.t('summarySpent'), Fmt.fmtMoney(r.spent, lang)) +
                        this.tile(this.t('summaryLast'), last || '—') +
                        this.tile(this.t('summaryDebt'), Fmt.fmtMoney(r.debt, lang), r.debt > 0 ? 'bad' : '') +
                        this.subscriptionTiles(r.subscriptions || [])
                    );
                }.bind(this))
                .catch(function (xhr) {
                    if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
                    $el.html('<div class="cw-meta">' + State.esc(this.translate('loadError', 'labels', 'CarWash')) + '</div>');
                }.bind(this));
        },
    });
});
