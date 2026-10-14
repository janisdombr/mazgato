/**
 * Wash board (the "Today's Orders" dashlet, R2): one card per car instead of a table row.
 *
 *  - plate big and linked (the ONLY navigation; the card body is not clickable — D8);
 *  - timer "on the bay" counted on the client from startedAt against the planned minutes
 *    of the ordered services (red on overrun, and only that is red — D2/D11);
 *  - work status and payment are TWO separate indicators, the card background never
 *    follows the money (D12);
 *  - 48 px buttons: Start / Done / Paid (D7).
 *
 * Business rules are unchanged from CLAUDE.md "Independent flags": Paid is offered only for
 * cash/card, an invoice is not a payment, a completed invoiced order leaves the board, a
 * completed cash/card order stays until it is paid.
 */
define(['custom:views/dashlets/base', 'custom:lib/period'], function (Dep, Period) {

    var TICK_MS = 30000;

    return Dep.extend({

        name: 'TodayOrders',

        autoFit: true,

        // The wash board carries the "At the entrance" block (plate recognition) above the cars; it hides
        // itself when the module is off or the role has no access. data-cw-fit wraps both so the
        // dashlet's auto height counts the block too.
        templateContent: '<div class="cw-pad"><div data-cw-fit><div class="today-orders-gate"></div><div class="today-orders-body"></div></div></div>',

        events: {
            'click .action-start': function (e) {
                this.act(e, {status: 'In Progress'}, 'started');
            },
            'click .action-noshow': function (e) {
                this.noShow(e);
            },
            'click .action-done': function (e) {
                this.act(e, {status: 'Completed'}, 'completed');
            },
            'click .action-paid': function (e) {
                this.act(e, {paymentStatus: 'Paid'}, 'markedPaid');
            },
        },

        afterRender: function () {
            this.orders = null;
            this.load();

            this.createView('gate', 'custom:views/anpr/entrance', {selector: '.today-orders-gate'}, function (view) {
                view.render();
            });

            this.stopTicking();
            // The timers are computed here, so a quiet re-render is all a tick needs.
            this.tickTimer = setInterval(function () {
                if (this.orders) { this.renderBoard(); }
            }.bind(this), TICK_MS);
        },

        onRemove: function () {
            this.stopTicking();
        },

        stopTicking: function () {
            if (this.tickTimer) { clearInterval(this.tickTimer); this.tickTimer = null; }
        },

        // Auto-refresh of the dashlet: reload data without flashing the loading skeleton.
        actionRefresh: function () {
            this.load();
        },

        esc: function (v) { return this.cwState.esc(v); },

        t: function (name) { return this.translate(name, 'labels', 'TodayOrders'); },

        // UTC API datetime -> local time (Europe/Riga). Guarded so a util change can never
        // blank the whole dashlet — it degrades to the raw time instead.
        fmtTime: function (v) {
            if (!v) { return ''; }
            try {
                var dt = this.getDateTime();
                return dt.toMoment(v).format(dt.timeFormat);
            } catch (e) {
                return String(v).slice(11, 16);
            }
        },

        body: function () { return this.$el.find('.today-orders-body'); },

        load: function () {
            var $body = this.body();

            if (!this.orders) { this.cwShowLoading($body); }

            // The list is oldest-first and capped, so old settled-looking rows must not be able to push today's cars off it
            // (a wash with a year of invoiced orders used to lose the newest car from the board): completed-but-unpaid
            // orders count only for the last two days (older debts live in the "Unpaid" figure and the order list),
            // and the cap is generous because the board filters client-side afterwards.
            var since = new Date(Date.now() - 48 * 3600 * 1000).toISOString().slice(0, 19).replace('T', ' ');

            Espo.Ajax.getRequest('WashOrder', {
                maxSize: 120,
                orderBy: 'orderDateTime',
                order: 'asc',
                // An order leaves this list once it is completed AND settled. "Settled"
                // means paid at the counter for cash/card; an invoiced order leaves as
                // soon as it is completed — the money is chased in accounting, not on the
                // ramp board. The filtering of invoiced orders happens client-side in
                // visible() (the API filter keeps every completed-but-unpaid order).
                where: [
                    {
                        type: 'or',
                        value: [
                            {type: 'in', attribute: 'status', value: ['New', 'In Progress', 'Reserved']},
                            {
                                type: 'and',
                                value: [
                                    {type: 'equals', attribute: 'status', value: 'Completed'},
                                    {type: 'notEquals', attribute: 'paymentStatus', value: 'Paid'},
                                    {type: 'greaterThanOrEquals', attribute: 'orderDateTime', value: since},
                                ],
                            },
                        ],
                    },
                ],
            }).then(function (data) {
                var list = this.visible(data.list || []);

                return this.loadDetails(list).then(function () {
                    this.orders = list;
                    this.renderBoard();
                }.bind(this));
            }.bind(this)).catch(function (xhr) {
                // A failed quiet refresh keeps the board that is already on screen.
                if (this.orders && !this.cwState.isAuthError(xhr)) { return; }
                this.orders = null;
                this.cwShowError(this.body(), xhr, this.load.bind(this));
            }.bind(this));
        },

        // A completed invoiced order is done as far as the ramp is concerned.
        visible: function (list) {
            return list.filter(function (o) {
                return !(o.status === 'Completed' && !this.isCounterPayment(o));
            }.bind(this));
        },

        // Services (planned minutes), priced lines (what is being done) and works
        // (who does it). Each is optional: when one fails the card simply shows less.
        loadDetails: function (list) {
            this.durations = this.durations || {};
            this.lines = {};
            this.workers = {};

            var soft = function (p) { return Promise.resolve(p).catch(function () { return {list: []}; }); };

            if (!list.length) { return soft(this.loadToday()).then(function (r) { this.today = r && r.orders ? r.orders : null; }.bind(this)); }

            var ids = list.map(function (o) { return o.id; });
            var inIds = [{type: 'in', attribute: 'washOrderId', value: ids}];

            return Promise.all([
                soft(Espo.Ajax.getRequest('WashService', {maxSize: 200, select: 'id,durationMinutes'})),
                soft(Espo.Ajax.getRequest('OrderAddon', {maxSize: 200, orderBy: 'sortOrder', order: 'asc', where: inIds, select: 'washOrderId,name,serviceId'})),
                soft(Espo.Ajax.getRequest('OrderWork', {maxSize: 200, where: inIds, select: 'washOrderId,employeeName'})),
                soft(this.loadToday()),
            ]).then(function (res) {
                this.today = res[3] && res[3].orders ? res[3].orders : null;
                (res[0].list || []).forEach(function (s) { this.durations[s.id] = parseInt(s.durationMinutes, 10) || 0; }.bind(this));
                (res[1].list || []).forEach(function (l) { (this.lines[l.washOrderId] = this.lines[l.washOrderId] || []).push(l); }.bind(this));
                (res[2].list || []).forEach(function (w) {
                    if (!w.employeeName) { return; }
                    var arr = this.workers[w.washOrderId] = this.workers[w.washOrderId] || [];
                    if (arr.indexOf(w.employeeName) < 0) { arr.push(w.employeeName); }
                }.bind(this));
            }.bind(this));
        },

        // The day's takings for the KPI strip: the same server numbers as the Statistics tab.
        loadToday: function () {
            var r = Period.range('today');

            return Espo.Ajax.postRequest('CarWash/action/statistics', {dateFrom: r.from + ' 00:00:00', dateTo: r.to + ' 23:59:59'});
        },

        statusLabel: function (status) {
            try {
                return this.getLanguage().translateOption(status, 'status', 'WashOrder');
            } catch (e) {
                return status;
            }
        },

        // Cash and card are collected at the counter; an invoice is not.
        isCounterPayment: function (o) {
            return o.paymentMethod !== 'Invoice';
        },

        // ---- derived facts for one order ----

        serviceText: function (o) {
            var lines = this.lines[o.id] || [];
            var names = lines.map(function (l) { return l.name; }).filter(Boolean);
            return names.length ? names.join(' + ') : (o.serviceName || '');
        },

        plannedMinutes: function (o) {
            var lines = this.lines[o.id] || [];
            var sum = 0;

            lines.forEach(function (l) { sum += this.durations[l.serviceId] || 0; }.bind(this));

            return sum || this.durations[o.serviceId] || 0;
        },

        // Personal data on a screen the whole counter sees: a private person's surname shows as its first letter ("Jānis B.").
        // Companies are not people - SIA / AS / IK ... names stay whole.
        shortName: function (name) {
            name = String(name || '').trim();

            if (!name || /\b(SIA|AS|IK|ZS|OÜ|UAB|AB|Ltd|LLC|GmbH|Oy)\b|["«»“”]/i.test(name)) { return name; }

            var parts = name.split(/\s+/);

            if (parts.length < 2) { return name; }

            var last = parts.pop();

            return parts.join(' ') + ' ' + last.charAt(0).toUpperCase() + '.';
        },

        performers: function (o) {
            var w = (this.workers[o.id] || []).slice();

            [o.washerName, o.dryerName, o.salonCleanerName].forEach(function (n) {
                if (n && w.indexOf(n) < 0 && !(this.workers[o.id] || []).length) { w.push(n); }
            }.bind(this));

            return w;
        },

        // elapsed minutes on the bay (In Progress) or in the queue (New); null otherwise
        elapsed: function (o) {
            if (o.status === 'In Progress') {
                return this.cwFmt.minutesSince(o.startedAt || o.orderDateTime);
            }
            if (o.status === 'New') {
                return this.cwFmt.minutesSince(o.orderDateTime);
            }
            return null;
        },

        units: function () { return {min: this.t('min'), h: this.t('hourShort')}; },

        // ---- rendering ----

        chip: function (cls, icon, text) {
            return '<span class="cw-chip cw-chip--' + cls + '"><i class="fas ' + icon + '" aria-hidden="true"></i>' + this.esc(text) + '</span>';
        },

        statusChip: function (o) {
            var map = {
                'Reserved': ['reserved', 'fa-calendar-check'],
                'New': ['wait', 'fa-hourglass-half'],
                'In Progress': ['progress', 'fa-droplet'],
                'Completed': ['done', 'fa-check'],
            };
            var m = map[o.status] || ['cancel', 'fa-circle-minus'];

            return this.chip(m[0], m[1], this.statusLabel(o.status));
        },

        // Money axis. Red "unpaid" only once the car is finished and the money is due;
        // before that it is a neutral "awaiting payment" (spec 5.2).
        payChip: function (o) {
            if (o.paymentStatus === 'Paid') { return this.chip('paid', 'fa-check', this.t('paid')); }
            if (o.paymentStatus === 'Invoiced') { return this.chip('invoiced', 'fa-file-invoice', this.t('invoiced')); }
            if (o.status === 'Completed') { return this.chip('unpaid', 'fa-euro-sign', this.t('unpaid')); }
            // A booking has no money question yet: no chip (less noise on the reservation card).
            if (o.status === 'Reserved') { return ''; }
            return this.chip('pending', 'fa-clock', this.t('payAwaiting'));
        },

        btn: function (cls, action, id, icon, text) {
            return '<button type="button" class="cw-btn ' + cls + ' cw-btn--block ' + action + '" data-id="' + id + '">' +
                '<i class="fas ' + icon + '" aria-hidden="true"></i><span>' + this.esc(text) + '</span></button>';
        },

        // A labelled link-button (text, never an icon alone; a calm outline so it cannot be taken for "delete").
        openBtn: function (o) {
            return '<a class="cw-btn cw-btn--ghost cw-btn--block order-open" href="#WashOrder/view/' + o.id + '">' +
                '<i class="fas fa-folder-open" aria-hidden="true"></i><span>' + this.esc(this.t('open')) + '</span></a>';
        },

        actionsHtml: function (o) {
            var isPaid = o.paymentStatus === 'Paid';
            var html = '';

            // Money is taken at the counter only for cash/card. An invoiced order is
            // settled in accounting, so the board offers no "Paid" button for it.
            if (!isPaid && this.isCounterPayment(o)) {
                html += this.btn('cw-btn--pay', 'action-paid', o.id, 'fa-euro-sign', this.t('markPaid'));
            }
            if (o.status === 'Reserved' || o.status === 'New') {
                html += this.btn('cw-btn--primary', 'action-start', o.id, 'fa-play', this.t('start'));
            }
            if (o.status === 'In Progress') {
                html += this.btn('cw-btn--done', 'action-done', o.id, 'fa-check', this.t('done'));
            }
            if (o.status === 'Reserved') {
                html += this.btn('', 'action-noshow', o.id, 'fa-user-slash', this.t('noShow'));
            }
            html += this.openBtn(o);

            return '<div class="cw-actions cw-actions--grid">' + html + '</div>';
        },

        // Reservation data the front desk needs: plate, client (name / phone), arrival time. A missing
        // one is flagged as an incomplete booking, never hidden behind a dash.
        missing: function (text) {
            return '<span class="cw-miss"><i class="fas fa-circle-exclamation" aria-hidden="true"></i>' + this.esc(text) + '</span>';
        },

        priceHtml: function (o) {
            var v = Number(o.finalPrice);

            if (v < 0) {
                return '<span class="cw-card__price cw-card__price--bad" title="' + this.esc(this.t('badPrice')) + '">' + this.esc(this.money(o.finalPrice)) + '</span>';
            }

            return '<span class="cw-card__price">' + this.esc(this.money(o.finalPrice)) + '</span>';
        },

        isIncomplete: function (o) {

            return o.status === 'Reserved' && (!o.vehicleName || !o.customerId || !o.orderDateTime);
        },

        cardHtml: function (o) {
            var mins = this.elapsed(o);
            var plan = this.plannedMinutes(o);
            var overrun = o.status === 'In Progress' && plan > 0 && mins != null && mins > plan;
            var cls = overrun ? 'overrun' : ({'Reserved': 'reserved', 'New': 'wait', 'In Progress': 'progress', 'Completed': 'done'}[o.status] || 'wait');

            var timer = '';
            if (o.status === 'In Progress' && mins != null) {
                timer = '<span class="cw-card__timer">' + this.esc(this.cwFmt.fmtDuration(mins, this.units())) +
                    (plan ? ' <small>/ ' + plan + '</small>' : '') + '</span>';
            } else if (o.status === 'New' && mins != null) {
                timer = '<span class="cw-card__timer"><small>' + this.esc(this.t('waitingFor')) + '</small> ' +
                    this.esc(this.cwFmt.fmtDuration(mins, this.units())) + '</span>';
            } else if (o.status === 'Reserved') {
                timer = '<span class="cw-card__timer">' + this.esc(this.fmtTime(o.orderDateTime)) + '</span>';
            }

            var bar = '';
            if (o.status === 'In Progress' && plan > 0 && mins != null) {
                bar = '<div class="cw-card__bar" role="progressbar" aria-valuemin="0" aria-valuemax="' + plan + '" aria-valuenow="' + Math.min(mins, plan) + '">' +
                    '<span style="width:' + Math.min(100, Math.round(mins * 100 / plan)) + '%"></span></div>';
            }

            var staff = this.performers(o).map(this.shortName.bind(this));
            var who = staff.join(' · ');
            var customer = this.esc(this.shortName(o.customerName || ''));
            var reserved = o.status === 'Reserved';
            var incomplete = this.isIncomplete(o);
            var phone = o.customerPhone || '';
            var badPrice = Number(o.finalPrice) < 0;

            // A temporary number (the car has no registration yet) is shown as such: the front desk replaces it with the real one.
            var tempChip = o.vehiclePlateTemporary ? ' <span class="cw-chip cw-chip--wait cw-chip--temp-plate" title="' + this.esc(this.translate('setRealPlate', 'labels', 'Vehicle')) + '">' + this.esc(this.t('tempPlate')) + '</span>' : '';
            var plate = o.vehicleName
                ? '<a class="cw-plate order-open" href="#WashOrder/view/' + o.id + '" title="' + this.esc(this.t('openOrder')) + '">' + this.esc(o.vehicleName) + '</a>' + tempChip
                : '<a class="cw-plate cw-plate--missing order-open" href="#WashOrder/view/' + o.id + '">' + this.esc(this.t('noPlate')) + '</a>';

            var timerHtml = timer;
            if (reserved && !o.orderDateTime) { timerHtml = this.missing(this.t('noTime')); }

            // Two kinds of people on one card, kept apart: the CLIENT (who pays) and the CREW (who washes). Each line has its own
            // label and icon, so a name is never guessed to be the other kind.
            var labelled = function (cls, icon, label, inner) {
                return '<div class="cw-card__who cw-card__who--' + cls + '"><span class="cw-card__who-label"><i class="fas ' + icon + '" aria-hidden="true"></i> ' +
                    this.esc(label) + '</span> ' + inner + '</div>';
            }.bind(this);

            var clientHtml = '';
            if (reserved) {
                clientHtml = labelled('client', 'fa-address-card', this.t('clientLabel'), o.customerId
                    ? customer + (phone ? ' · <span class="cw-card__phone">' + this.esc(phone) + '</span>' : '')
                    : this.missing(this.t('noClient')));
            } else if (customer) {
                clientHtml = labelled('client', 'fa-address-card', this.t('clientLabel'), customer);
            }

            var crewHtml = '';
            if (who) {
                crewHtml = labelled('crew', 'fa-hard-hat', this.t(staff.length > 1 ? 'crewLabelMany' : 'crewLabel'), this.esc(who));
            } else if (o.status === 'New' || o.status === 'In Progress') {
                crewHtml = labelled('crew', 'fa-hard-hat', this.t('crewLabel'), '<span class="cw-meta">' + this.esc(this.t('noCrew')) + '</span>');
            }

            return '<div class="cw-card cw-card--' + cls + (incomplete ? ' cw-card--incomplete' : '') + '">' +
                '<div class="cw-card__head">' + plate + timerHtml + '</div>' + bar +
                (incomplete ? '<div class="cw-card__warn"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ' + this.esc(this.t('incompleteBooking')) + '</div>' : '') +
                '<div class="cw-card__line"><span class="cw-card__service">' + this.esc(this.serviceText(o)) + '</span>' + this.priceHtml(o) + '</div>' +
                clientHtml + crewHtml +
                '<div class="cw-card__chips">' + this.statusChip(o) + (overrun ? this.chip('overrun', 'fa-stopwatch', this.t('overrun')) : '') +
                    (badPrice ? this.chip('unpaid', 'fa-triangle-exclamation', this.t('badPrice')) : '') + this.payChip(o) + '</div>' +
                this.actionsHtml(o) +
            '</div>';
        },

        translateMethod: function (m) {
            try { return this.getLanguage().translateOption(m, 'paymentMethod', 'WashOrder'); } catch (e) { return m; }
        },

        rowHtml: function (o) {
            return '<div class="cw-row">' +
                '<div class="cw-row__main">' +
                    '<a class="cw-plate cw-plate--sm order-open" href="#WashOrder/view/' + o.id + '">' + this.esc(o.vehicleName || this.t('noPlate')) + '</a>' +
                    '<span class="cw-row__price' + (Number(o.finalPrice) < 0 ? ' cw-card__price--bad' : '') + '">' + this.esc(this.money(o.finalPrice)) + '</span>' +
                    '<span class="cw-meta">' + this.esc(this.translateMethod(o.paymentMethod)) + (o.finishedAt ? ' · ' + this.esc(this.fmtTime(o.finishedAt)) : '') + '</span>' +
                    this.payChip(o) +
                '</div>' +
                this.btn('cw-btn--pay', 'action-paid', o.id, 'fa-euro-sign', this.t('markPaid')).replace('cw-btn--block', '') +
            '</div>';
        },

        kpi: function (label, value, mod) {
            return '<div class="cw-kpi' + (mod ? ' cw-kpi--' + mod : '') + '"><div class="cw-kpi__label">' + this.esc(label) + '</div>' +
                '<div class="cw-kpi__value">' + this.esc(value) + '</div></div>';
        },

        renderBoard: function () {
            var list = this.orders || [];
            var $body = this.body();

            if (!list.length) {
                $body.html(this.cwState.empty({
                    icon: 'fas fa-car-side',
                    title: this.t('emptyTitle'),
                    hint: this.t('emptyHint'),
                }));
                this.fitToContent();
                return;
            }

            var active = list.filter(function (o) { return o.status !== 'Completed'; });
            var toPay = list.filter(function (o) { return o.status === 'Completed'; });

            // Overrun first, then longest on the bay, then the queue, then today's reservations.
            var rank = function (o) {
                var plan = this.plannedMinutes(o);
                var m = this.elapsed(o) || 0;
                if (o.status === 'In Progress') { return (plan > 0 && m > plan ? 0 : 1) * 1e6 - m; }
                if (o.status === 'New') { return 2e6 - m; }
                return 3e6;
            }.bind(this);
            active.sort(function (a, b) { return rank(a) - rank(b); });

            var onWash = active.filter(function (o) { return o.status === 'In Progress'; }).length;
            var waiting = active.filter(function (o) { return o.status === 'New'; }).length;
            var due = 0;
            toPay.forEach(function (o) { due += Number(o.finalPrice) || 0; });

            var html = '<div class="cw-kpis">' +
                (this.today ? this.kpi(this.t('kpiToday'), (this.today.completed || 0) + ' · ' + this.money(this.today.revenue || 0), 'good') : '') +
                this.kpi(this.t('kpiOnWash'), String(onWash)) +
                this.kpi(this.t('kpiWaiting'), String(waiting)) +
                this.kpi(this.t('kpiToPay'), toPay.length ? toPay.length + ' · ' + this.money(due) : '0', toPay.length ? 'bad' : '') +
            '</div>';

            if (active.length) {
                html += '<div class="cw-board">' + active.map(this.cardHtml.bind(this)).join('') + '</div>';
            }

            if (toPay.length) {
                html += '<div class="cw-section-title">' + this.esc(this.t('sectionToPay')) + ' (' + toPay.length + ')</div>' +
                    '<div class="cw-rows">' + toPay.map(this.rowHtml.bind(this)).join('') + '</div>';
            }

            $body.html(html);
            this.fitToContent();
        },

        // ---- actions ----

        // A reservation whose customer did not come: flags it and releases the bay.
        noShow: function (e) {
            var $b = $(e.currentTarget);
            var id = $b.data('id');

            if (!id || $b.hasClass('is-busy')) { return; }

            $b.addClass('is-busy');

            Espo.Ajax.postRequest('Booking/action/noShow', {id: id})
                .then(function () {
                    Espo.Ui.success(this.t('noShowDone'));
                    this.load();
                }.bind(this))
                .catch(function (xhr) {
                    $b.removeClass('is-busy');
                    if (this.cwState.isAuthError(xhr)) { this.cwShowError(this.body(), xhr, this.load.bind(this)); }
                }.bind(this));
        },

        act: function (e, patch, doneKey) {
            var $b = $(e.currentTarget);
            var id = $b.data('id');

            if (!id || $b.hasClass('is-busy')) { return; }

            $b.addClass('is-busy');

            Espo.Ajax.putRequest('WashOrder/' + id, patch)
                .then(function () {
                    Espo.Ui.success(this.t(doneKey));
                    this.load();
                }.bind(this))
                .catch(function (xhr) {
                    // e.g. 409 "one car, one ramp" — the stock handler shows the server's message.
                    $b.removeClass('is-busy');
                    if (this.cwState.isAuthError(xhr)) { this.cwShowError(this.body(), xhr, this.load.bind(this)); }
                }.bind(this));
        },

    });
});
