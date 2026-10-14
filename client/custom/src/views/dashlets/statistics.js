/**
 * Management overview for the shared period (R3): six KPI tiles with the change against the
 * previous period and a trend line, revenue by day, load by hour, share of services, top
 * customers, employees. Everything is drawn from the numbers CarWash/action/statistics
 * returns (`previous`, `daily`, `byHour` included); the client only formats. Each chart has
 * its table underneath, folded under "Show table".
 */
define(['custom:views/dashlets/base', 'custom:lib/charts', 'custom:lib/period'], function (Dep, Charts, Period) {

    return Dep.extend({

        name: 'Statistics',

        autoFit: true,

        templateContent: '<div class="cw-pad"><div class="statistics-body" data-cw-fit></div></div>',

        afterRender: function () {
            if (this.unsubscribe) { this.unsubscribe(); }

            // One period switch for the whole tab — see lib/period.js.
            this.unsubscribe = Period.subscribe(function () { this.load(); }.bind(this));
            this.load();
        },

        onRemove: function () {
            if (this.unsubscribe) { this.unsubscribe(); this.unsubscribe = null; }
        },

        actionRefresh: function () { this.load(); },

        esc: function (v) { return this.cwState.esc(v); },

        t: function (name) { return this.translate(name, 'labels', 'Statistics'); },

        lang: function () { return this.getPreferences().get('language') || this.getConfig().get('language'); },

        load: function () {
            var $body = this.$el.find('.statistics-body');

            this.cwShowLoading($body);

            // Several period changes in a row: only the LAST answer may draw.
            var seq = this.seq = (this.seq || 0) + 1;

            Espo.Ajax.postRequest('CarWash/action/statistics', Period.payload())
                .then(function (res) { if (seq === this.seq) { this.renderAll(res || {}); } }.bind(this))
                .catch(function (xhr) { this.cwShowError($body, xhr, this.load.bind(this)); }.bind(this));
        },

        // "13.07" style short label for an ISO day, in the user's date notation.
        dayLabel: function (iso) {
            return iso.slice(8, 10) + '.' + iso.slice(5, 7);
        },

        /** KPI tile: value, delta vs previous period (invert = lower is better), optional trend. */
        tile: function (label, valueText, cur, prev, opts) {
            opts = opts || {};
            var d = Charts.delta(cur, prev);
            var good = d && (opts.invert ? d.dir === 'down' : d.dir === 'up');
            var bad = d && (opts.invert ? d.dir === 'up' : d.dir === 'down');
            var cls = 'cw-kpi' + (opts.mod ? ' cw-kpi--' + opts.mod : '');
            var delta = d
                ? '<div class="cw-kpi__delta cw-kpi__delta--' + (good ? 'up' : (bad ? 'down' : 'flat')) + '">' +
                    '<i class="fas ' + (d.dir === 'up' ? 'fa-arrow-trend-up' : (d.dir === 'down' ? 'fa-arrow-trend-down' : 'fa-minus')) + '" aria-hidden="true"></i> ' +
                    this.esc(Charts.fmtDelta(d, this.lang())) + ' <span class="cw-kpi__vs">' + this.esc(this.t('vsPrevious')) + '</span></div>'
                : '';

            return '<div class="' + cls + '"><div class="cw-kpi__label">' + this.esc(label) + '</div>' +
                '<div class="cw-kpi__value">' + this.esc(valueText) + '</div>' + delta + (opts.spark || '') + '</div>';
        },

        table: function (head, rows) {
            if (!rows) { return ''; }

            return '<details class="cw-table-toggle"><summary>' + this.esc(this.t('showTable')) + '</summary>' +
                '<table class="cw-table"><thead><tr>' + head + '</tr></thead><tbody>' + rows + '</tbody></table></details>';
        },

        block: function (title, inner) {
            return '<div class="cw-card-block"><h3>' + this.esc(title) + '</h3>' + inner + '</div>';
        },

        renderAll: function (res) {
            var t = this.t.bind(this);
            var o = res.orders || {};
            var lab = res.labour || {};
            var cust = res.customers || {};
            var prev = res.previous || {};
            var daily = res.daily || [];
            var $body = this.$el.find('.statistics-body');
            var m = this.money.bind(this);

            // D23: an empty period is "no orders", not a row of zeros that looks like broken accounting.
            if (!(o.total > 0)) {
                $body.html(this.cwState.empty({
                    icon: 'fas fa-chart-simple', title: t('noOrdersPeriod'), hint: t('noOrdersPeriodHint'),
                }));
                return;
            }

            var revSpark = Charts.sparkline(daily.map(function (d) { return d.revenue; }), {title: t('revenue'), cls: 'cw-chart-1'});
            var ordSpark = Charts.sparkline(daily.map(function (d) { return d.orders; }), {title: t('ordersCompleted'), cls: 'cw-chart-2'});

            var html = '<div class="cw-kpis cw-kpis--stats">' +
                this.tile(t('revenue'), m(o.revenue || 0), o.revenue, prev.revenue, {mod: 'good', spark: revSpark}) +
                this.tile(t('ordersCompleted'), (o.completed || 0) + ' / ' + (o.total || 0), o.completed, prev.completed, {spark: ordSpark}) +
                this.tile(t('averageCheck'), m(o.averageCheck || 0), o.averageCheck, prev.averageCheck) +
                this.tile(t('unpaid'), m(o.unpaid || 0), o.unpaid, prev.unpaid, {invert: true, mod: o.unpaid ? 'bad' : ''}) +
                // Wages are the administrator's: the server omits cost / margin for everyone else.
                (lab.cost === undefined ? '' : this.tile(t('labourCost'), m(lab.cost || 0), lab.cost, prev.labourCost, {invert: true})) +
                (lab.margin === undefined ? '' : this.tile(t('margin'), m(lab.margin || 0), lab.margin, prev.margin, {mod: 'good'})) +
            '</div>';

            // Revenue by day
            var bars = Charts.bars(daily.map(function (d) {
                return {label: this.dayLabel(d.date), value: d.revenue, text: m(d.revenue)};
            }.bind(this)), {title: t('chartRevenueByDay'), maxText: m(Math.max.apply(null, daily.map(function (d) { return d.revenue; }).concat([0])))});

            var dayRows = daily.filter(function (d) { return d.orders || d.revenue; }).map(function (d) {
                return '<tr><td>' + this.esc(this.dayLabel(d.date)) + '</td><td class="cw-right">' + d.orders + '</td><td class="cw-right"><b>' + this.esc(m(d.revenue)) + '</b></td></tr>';
            }.bind(this)).join('');

            html += this.block(t('chartRevenueByDay'), bars + this.table('<th>' + t('day') + '</th><th class="cw-right">' + t('ordersLabel') + '</th><th class="cw-right">' + t('revenue') + '</th>', dayRows));

            // Load by hour
            var heat = Charts.heatHours(res.byHour || [], {title: t('chartLoadByHour')});

            if (heat) {
                var hourRows = (res.byHour || []).map(function (n, h) {
                    return n ? '<tr><td>' + (h < 10 ? '0' : '') + h + ':00</td><td class="cw-right">' + n + '</td></tr>' : '';
                }).join('');

                html += this.block(t('chartLoadByHour'), heat + this.table('<th>' + t('hour') + '</th><th class="cw-right">' + t('ordersLabel') + '</th>', hourRows));
            }

            // Share of services
            var services = res.services || [];
            var donut = Charts.donut(services.map(function (s) { return {label: s.name || '—', value: s.sum}; }), {title: t('chartServiceShare'), otherLabel: t('other')});
            var legend = donut.legend.map(function (it) {
                return '<li class="' + it.cls + '"><i></i><span class="cw-grow">' + this.esc(it.label) + '</span><b>' + this.esc(m(it.value)) + '</b></li>';
            }.bind(this)).join('');
            var svcRows = services.map(function (s) {
                return '<tr><td>' + this.esc(s.name) + '</td><td class="cw-right">' + s.count + '</td><td class="cw-right"><b>' + this.esc(m(s.sum)) + '</b></td></tr>';
            }.bind(this)).join('');

            html += this.block(t('chartServiceShare'),
                (donut.svg ? '<div class="cw-flex cw-flex--wide">' + donut.svg + '<ul class="cw-legend">' + legend + '</ul></div>' : '') +
                this.table('<th>' + t('service') + '</th><th class="cw-right">' + t('count') + '</th><th class="cw-right">€</th>', svcRows));

            // Top customers
            var top = cust.top || [];
            var custRows = top.map(function (c) {
                return '<tr><td>' + this.esc(c.name) + '</td><td class="cw-right">' + c.count + '</td><td class="cw-right"><b>' + this.esc(m(c.sum)) + '</b></td></tr>';
            }.bind(this)).join('');

            if (top.length) {
                html += this.block(t('chartTopCustomers'),
                    Charts.hbars(top.map(function (c) { return {label: c.name || '—', value: c.sum, text: m(c.sum)}; }), {title: t('chartTopCustomers'), cls: 'cw-chart-3'}) +
                    this.table('<th>' + t('customer') + '</th><th class="cw-right">' + t('count') + '</th><th class="cw-right">€</th>', custRows));
            }

            // Employees
            var emps = res.employees || [];
            var showPay = emps.length && emps[0].sum !== undefined;
            var empRows = emps.map(function (e) {
                return '<tr><td>' + this.esc(e.name) + '</td><td class="cw-right">' + e.works + '</td><td class="cw-right">' + e.hours + '</td>' +
                    (showPay ? '<td class="cw-right"><b>' + this.esc(m(e.sum)) + '</b></td>' : '') + '</tr>';
            }.bind(this)).join('');

            if (emps.length) {
                html += this.block(t('chartEmployees'),
                    Charts.hbars(emps.map(function (e) {
                        return showPay ? {label: e.name || '—', value: e.sum, text: m(e.sum) + ' · ' + e.works}
                                       : {label: e.name || '—', value: e.works, text: String(e.works)};
                    }), {title: t('chartEmployees'), cls: 'cw-chart-4'}) +
                    this.table('<th>' + t('employee') + '</th><th class="cw-right">' + t('works') + '</th><th class="cw-right">' + t('hours') + '</th>' + (showPay ? '<th class="cw-right">€</th>' : ''), empRows));
            }

            // Loyalty (module loyalty): what was given away and the points that moved. Absent while the module is off.
            if (res.loyalty) {
                var ly = res.loyalty;
                var dd = ly.discounts || {};
                var pp = ly.points || {};
                var rr = ly.referrals || {};
                var lrows = [
                    [t('loyaltyPromo'), m(dd.promo || 0)],
                    [t('loyaltyFree'), m(dd.free || 0)],
                    [t('loyaltyPointsDiscount'), m(dd.points || 0)],
                    [t('loyaltyDiscountTotal'), m(dd.total || 0)],
                    [t('loyaltyEarned'), String(pp.earned || 0)],
                    [t('loyaltyRedeemed'), String(pp.redeemed || 0)],
                    [t('loyaltyExpired'), String(pp.expired || 0)],
                    [t('loyaltyReferrals'), String(rr.rewarded || 0)],
                ].map(function (r) {
                    return '<tr><td>' + this.esc(r[0]) + '</td><td class="cw-right"><b>' + this.esc(r[1]) + '</b></td></tr>';
                }.bind(this)).join('');

                html += this.block(t('loyaltyTitle'),
                    '<div class="cw-kpis cw-kpis--stats">' +
                        this.tile(t('loyaltyDiscountTotal'), m(dd.total || 0), dd.total, undefined) +
                        this.tile(t('loyaltyEarned'), String(pp.earned || 0), pp.earned, undefined) +
                        this.tile(t('loyaltyRedeemed'), String(pp.redeemed || 0), pp.redeemed, undefined) +
                        this.tile(t('loyaltyReferrals'), String(rr.rewarded || 0), rr.rewarded, undefined) +
                    '</div>' +
                    '<table class="cw-table"><tbody>' + lrows + '</tbody></table>');
            }

            $body.html(html);
        },

    });
});
