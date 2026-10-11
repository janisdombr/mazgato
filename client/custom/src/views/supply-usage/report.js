/**
 * Supply-usage report body — shared by the dashboard dashlet and the "Usage report"
 * dialog in the Supplies section, so both always show the same numbers.
 *
 * Two tables over the same period: what each service cost in supplies (spend tied to the
 * services that caused it) and what was consumed per supply.
 */
define(['view', 'custom:lib/state', 'custom:lib/format', 'custom:lib/period'], function (Dep, State, Fmt, Period) {

    return Dep.extend({

        templateContent: `
            <div class="supply-usage">
                <div class="cw-flex cw-bar">
                    <input type="date" class="form-control field-from cw-grow">
                    <span>—</span>
                    <input type="date" class="form-control field-to cw-grow">
                    <button class="btn btn-default action-refresh-range">
                        {{translate 'refresh' category='labels' scope='SupplyUsage'}}
                    </button>
                </div>
                <div class="supply-usage-body"></div>
            </div>
        `,

        events: {
            'click .action-refresh-range': function () { this.load(); },
        },

        // In the Statistics tab (options.usePeriod) the report has no date inputs of its own: it
        // follows the tab's single period switch. In the Supplies dialog it keeps them.
        afterRender: function () {
            if (this.options.usePeriod) {
                this.$el.find('.cw-bar').hide();

                if (this.unsubscribe) { this.unsubscribe(); }

                this.unsubscribe = Period.subscribe(function () { this.load(); }.bind(this));
                this.load();

                return;
            }

            // Default period: the current month — "how much did we spend this month".
            var now = new Date();
            var first = new Date(now.getFullYear(), now.getMonth(), 1);
            var last = new Date(now.getFullYear(), now.getMonth() + 1, 0);

            this.$el.find('.field-from').val(this.toIso(first));
            this.$el.find('.field-to').val(this.toIso(last));

            this.load();
        },

        // Local calendar date -> YYYY-MM-DD. toISOString() would shift by the tz offset
        // and hand back the previous day for Europe/Riga.
        toIso: function (d) {
            var m = d.getMonth() + 1;
            var day = d.getDate();

            return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
        },

        esc: function (v) { return State.esc(v); },

        money: function (v) { return Fmt.fmtMoney(v, this.getPreferences().get('language') || this.getConfig().get('language')); },

        t: function (name) { return this.translate(name, 'labels', 'SupplyUsage'); },

        num: function (v) { return v != null ? v : ''; },

        onRemove: function () {
            if (this.unsubscribe) { this.unsubscribe(); this.unsubscribe = null; }
        },

        load: function () {
            var payload;

            if (this.options.usePeriod) {
                payload = Period.payload();
            } else {
                var from = this.$el.find('.field-from').val();
                var to = this.$el.find('.field-to').val();

                payload = {};
                if (from) { payload.dateFrom = from + ' 00:00:00'; }
                if (to) { payload.dateTo = to + ' 23:59:59'; }
            }

            var $body = this.$el.find('.supply-usage-body');
            var cwT = function (k) { return this.translate(k, 'labels', 'CarWash'); }.bind(this);

            $body.html(State.loading(cwT('loading')));

            var seq = this.seq = (this.seq || 0) + 1;

            Espo.Ajax.postRequest('CarWash/action/supplyUsage', payload)
                .then(function (res) { if (seq === this.seq) { this.renderTable(res || {}); } }.bind(this))
                .catch(function (xhr) {
                    if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
                    var auth = State.isAuthError(xhr);
                    $body.html(State.error({
                        auth: auth, title: cwT(auth ? 'sessionExpired' : 'loadError'), hint: cwT(auth ? 'sessionExpiredHint' : 'loadErrorHint'),
                        retryLabel: cwT('retry'), loginLabel: cwT('relogin'),
                    }));
                    $body.find('.action-retry').on('click', this.load.bind(this));
                    $body.find('.action-relogin').on('click', function () { window.location.reload(); });
                }.bind(this));
        },

        // Headline counters: orders, services performed, works recorded.
        renderSummary: function (res) {
            var t = this.t.bind(this);

            return '<div class="cw-flex cw-bar">' +
                '<span class="label label-primary">' + t('orders') + ': ' + (res.ordersCount || 0) + '</span> ' +
                '<span class="label label-default">' + t('services') + ': ' + (res.servicesCount || 0) + '</span> ' +
                '<span class="label label-info">' + t('works') + ': ' + (res.worksCount || 0) + '</span>' +
            '</div>';
        },

        renderServices: function (services) {
            if (!services.length) { return ''; }

            var t = this.t.bind(this);

            var body = services.map(function (s) {
                return '<tr>' +
                    '<td>' + this.esc(s.serviceName) + '</td>' +
                    '<td class="cw-right">' + this.num(s.count) + '</td>' +
                    '<td class="cw-right">' + this.money(s.costPerUnit) + '</td>' +
                    '<td class="cw-right"><b>' + this.money(s.cost) + '</b></td>' +
                '</tr>';
            }.bind(this)).join('');

            return '<h5 class="cw-h">' + t('byService') + '</h5>' +
                '<table class="cw-table">' +
                    '<thead><tr>' +
                        '<th>' + t('service') + '</th>' +
                        '<th class="cw-right">' + t('times') + '</th>' +
                        '<th class="cw-right">' + t('costPerUnit') + '</th>' +
                        '<th class="cw-right">' + t('cost') + '</th>' +
                    '</tr></thead>' +
                    '<tbody>' + body + '</tbody>' +
                '</table>';
        },

        renderSupplies: function (rows, totalCost) {
            var t = this.t.bind(this);

            var body = rows.map(function (r) {
                return '<tr>' +
                    '<td>' + this.esc(r.supplyName) + '</td>' +
                    '<td class="cw-right">' + this.num(r.quantity) +
                        (r.unit ? ' ' + this.esc(r.unit) : '') + '</td>' +
                    '<td class="cw-right">' + this.money(r.unitCost) + '</td>' +
                    '<td class="cw-right"><b>' + this.money(r.cost) + '</b></td>' +
                '</tr>';
            }.bind(this)).join('');

            return '<h5 class="cw-h">' + t('bySupply') + '</h5>' +
                '<table class="cw-table">' +
                    '<thead><tr>' +
                        '<th>' + t('supply') + '</th>' +
                        '<th class="cw-right">' + t('quantity') + '</th>' +
                        '<th class="cw-right">' + t('unitCost') + '</th>' +
                        '<th class="cw-right">' + t('cost') + '</th>' +
                    '</tr></thead>' +
                    '<tbody>' + body + '</tbody>' +
                    '<tfoot><tr class="cw-total">' +
                        '<td colspan="3"><b>' + t('total') + '</b></td>' +
                        '<td class="cw-right"><b>' + this.money(totalCost || 0) + '</b></td>' +
                    '</tr></tfoot>' +
                '</table>';
        },

        renderTable: function (res) {
            var rows = res.rows || [];
            var services = res.services || [];
            var html = this.renderSummary(res);

            if (!rows.length && !services.length) {
                html += State.empty({icon: 'fas fa-boxes-stacked', title: this.t('noData')});
            } else {
                html += this.renderServices(services);

                if (rows.length) {
                    html += this.renderSupplies(rows, res.totalCost);
                }
            }

            this.$el.find('.supply-usage-body').html(html);
        },

    });
});
