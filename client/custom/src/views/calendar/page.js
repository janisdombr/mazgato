/**
 * #Calendar: the order calendar. Day (hours x load bar x the cars), Week (7 columns x hour rows: booked / lines, colour scale, chips),
 * Month (a tile per day: load %). All numbers come from Booking/action/range (the Planner); this view only lays them out.
 * A click on a free cell opens the ordinary Reserved-order create form with the time filled in: the human saves.
 */
define(['view', 'custom:lib/state', 'custom:lib/calendar'], function (View, State, Cal) {

    var esc = State.esc;
    var KEY = 'cwCalendar';
    var VIEWS = ['day', 'week', 'month'];

    return View.extend({

        templateContent: '<div class="page-header"><h3>{{translate "title" category="labels" scope="CwCalendar"}}</h3></div><div class="cw-cal cw-pad"></div>',

        events: {
            'click .cw-cal-prev': function () { this.go(Cal.shift(this.mode, this.date, -1)); },
            'click .cw-cal-next': function () { this.go(Cal.shift(this.mode, this.date, 1)); },
            'click .cw-cal-today': function () { this.go((this.data && this.data.today) || this.getDateTime().getToday()); },
            'click .cw-cal-refresh': function () { this.load(); },
            'click .cw-cal-mode': function (e) { this.setMode($(e.currentTarget).data('mode')); },
            'click .cw-cal-book': function (e) {
                var $b = $(e.currentTarget);

                this.book($b.data('date'), parseInt($b.data('min'), 10));
            },
            'click .cw-cal-open-day': function (e) { this.date = $(e.currentTarget).data('date'); this.setMode('day'); },
        },

        setup: function () {
            var saved = {};

            try { saved = JSON.parse(window.sessionStorage.getItem(KEY) || '{}'); } catch (e) { /* ignore */ }

            this.mode = VIEWS.indexOf(saved.mode) >= 0 ? saved.mode : 'week';
            this.date = Cal.isDate(saved.date) ? saved.date : null;
            this.ensureStyles();
        },

        /** The markup uses --cw-* tokens, which only the CarWash themes define: a user on a stock theme gets the standalone sheet. */
        ensureStyles: function () {
            if (document.getElementById('cw-standalone-css')) { return; }

            var probe = window.getComputedStyle(document.documentElement).getPropertyValue('--cw-panel');

            if (probe && probe.trim()) { return; }

            var dark = false;

            try { dark = !!this.getThemeManager().getParam('isDark'); } catch (e) { /* light */ }

            var link = document.createElement('link');

            link.id = 'cw-standalone-css';
            link.rel = 'stylesheet';
            link.href = 'client/custom/css/cw-standalone' + (dark ? '-dark' : '') + '.css?r=' + (this.getConfig().get('cacheTimestamp') || '');
            document.head.appendChild(link);
        },

        t: function (k) { return this.translate(k, 'labels', 'CwCalendar'); },

        dayName: function (date) {
            var names = this.getLanguage().get('Global', 'lists', 'dayNamesShort');

            return (names && names[Cal.dow(date)]) || ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][Cal.dow(date)];
        },

        monthName: function (date) {
            var names = this.getLanguage().get('Global', 'lists', 'monthNames');
            var i = parseInt(date.slice(5, 7), 10) - 1;

            return (names && names[i]) || date.slice(0, 7);
        },

        afterRender: function () {
            this.$cal = this.$el.find('.cw-cal');
            this.load();
        },

        remember: function () {
            try { window.sessionStorage.setItem(KEY, JSON.stringify({mode: this.mode, date: this.date})); } catch (e) { /* ignore */ }
        },

        go: function (date) { this.date = date; this.load(); },

        setMode: function (m) { if (VIEWS.indexOf(m) >= 0) { this.mode = m; this.load(); } },

        load: function () {
            var self = this, seq = this.seq = (this.seq || 0) + 1;
            if (!this.date) { this.date = this.getDateTime().getToday(); }

            var r = Cal.rangeFor(this.mode, this.date);

            if (!this.data) { this.$cal.html(State.loading(this.t('loading'))); }

            Espo.Ajax.postRequest('Booking/action/range', r).then(function (d) {
                if (seq !== self.seq) { return; }

                self.data = d;
                self.remember();
                self.paint();
            }).catch(function (xhr) {
                if (seq !== self.seq) { return; }

                self.data = null;
                var auth = State.isAuthError(xhr);
                var off = xhr && xhr.responseText && /module_disabled/.test(xhr.responseText);

                self.$cal.html(State.error({
                    auth: auth && !off, title: off ? self.t('moduleOff') : self.t('loadError'), retryLabel: self.t('retry'), loginLabel: self.t('signIn'),
                }));
                self.$cal.find('.action-retry').on('click', self.load.bind(self));
                self.$cal.find('.action-relogin').on('click', function () { window.location.reload(); });
            });
        },

        /** 12.10 from 2026-10-12 */
        dm: function (date) { return date.slice(8) + '.' + date.slice(5, 7); },

        /** Toolbar title: the day, the week ("Week 42 - 13.10-19.10") or the month. */
        title: function () {
            var d = this.date;

            if (this.mode === 'day') { return this.dayName(d) + ' ' + d; }
            if (this.mode === 'week') { var w = Cal.weekRange(d); return this.t('week') + ' ' + Cal.weekNumber(d) + ' · ' + this.dm(w.from) + ' – ' + this.dm(w.to); }

            return this.monthName(d) + ' ' + d.slice(0, 4);
        },

        paint: function () {
            var self = this, d = this.data;
            var bar = '<div class="cw-cal__bar"><div class="cw-cal__nav">' +
                '<button type="button" class="cw-btn cw-cal-prev" aria-label="' + esc(this.t('prev')) + '"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>' +
                '<button type="button" class="cw-btn cw-cal-today">' + esc(this.t('today')) + '</button>' +
                '<button type="button" class="cw-btn cw-cal-next" aria-label="' + esc(this.t('next')) + '"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>' +
                '<h4 class="cw-cal__title" aria-live="polite">' + esc(this.title()) + '</h4></div>' +
                '<div class="cw-cal__modes" role="group" aria-label="' + esc(this.t('view')) + '">' + VIEWS.map(function (m) {
                    return '<button type="button" class="cw-btn cw-cal-mode' + (m === self.mode ? ' is-on' : '') + '" data-mode="' + m + '" aria-pressed="' + (m === self.mode ? 'true' : 'false') + '">' + esc(self.t(m)) + '</button>';
                }).join('') + '<button type="button" class="cw-btn cw-cal-refresh" aria-label="' + esc(this.t('refresh')) + '"><i class="fas fa-rotate" aria-hidden="true"></i></button></div></div>';
            var t = Cal.totals(d.days);
            var sum = '<p class="cw-cal__sum">' + esc(this.t('cars')) + ': <strong>' + t.cars + '</strong> · ' + esc(this.t('load')) + ': <strong>' + t.pct + ' %</strong>' +
                (t.over ? ' · <span class="cw-cal__flag"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ' + esc(this.t('overCount')) + ': ' + t.over + '</span>' : '') +
                (d.sharePercent < 100 ? ' · ' + esc(this.t('onlineShare')) + ': ' + d.sharePercent + ' %' : '') + '</p>';
            var body = this.mode === 'month' ? this.paintMonth(d) : (this.mode === 'week' ? this.paintWeek(d) : this.paintDay(d));

            this.$cal.html(bar + sum + this.legend() + body);
        },

        legend: function () {
            var self = this;

            return '<ul class="cw-cal__legend" aria-label="' + esc(this.t('legend')) + '">' + ['free', 'busy', 'tight', 'full', 'over', 'closed'].map(function (l) {
                return '<li><span class="cw-cal__sw cw-cal__sw--' + l + '" aria-hidden="true"></span>' + esc(self.t('lvl_' + l)) + '</li>';
            }).join('') + '<li><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ' + esc(this.t('overridden')) + '</li></ul>';
        },

        chip: function (o, full) {
            var flags = (o.capacityOverride ? ' <i class="fas fa-triangle-exclamation" title="' + esc(this.t('overridden')) + '" aria-hidden="true"></i><span class="cw-sr">' + esc(this.t('overridden')) + '</span>' : '') +
                (o.noShow ? ' <i class="fas fa-user-slash" aria-hidden="true"></i>' : '');
            var time = Cal.hm(o.startMin);

            return '<a class="cw-cal__chip cw-cal__chip--' + Cal.chipClass(o.status) + '" href="#WashOrder/view/' + esc(o.id) + '" title="' + esc(o.number + ' · ' + o.customer + ' · ' + o.service) + '">' +
                '<span class="cw-cal__t">' + time + '</span> <strong>' + esc(o.plate || o.number) + '</strong>' + flags +
                (full ? '<span class="cw-cal__sub">' + esc(o.service) + (o.customer ? ' · ' + esc(o.customer) : '') + ' · ' + esc(o.minutes) + ' ' + esc(this.t('min')) +
                    ' · ' + esc(this.t('src_' + o.source)) + (o.post ? ' · ' + esc(this.t('bay')) + ' ' + o.post : '') + '</span>' : '') + '</a>';
        },

        /** A free-cell button. */
        bookBtn: function (date, hour, label, aria) {
            var min = Cal.startFor(hour, {
                isToday: date === this.data.today, nowMin: this.nowMin(), slotMinutes: this.data.slotMinutes,
            });

            return '<button type="button" class="cw-cal-book cw-cal__book" data-date="' + esc(date) + '" data-min="' + min + '" aria-label="' + esc(aria) + '">' + label + '</button>';
        },

        /** Minutes since local midnight in the wash's zone (from the clock, converted with Intl: never the browser's own zone). */
        nowMin: function () {
            try {
                var p = {};

                new Intl.DateTimeFormat('en-US', {timeZone: this.data.tz, hourCycle: 'h23', hour: '2-digit', minute: '2-digit'}).formatToParts(new Date()).forEach(function (x) { p[x.type] = x.value; });

                return (+p.hour) * 60 + (+p.minute);
            } catch (e) { return null; }
        },

        cellAria: function (day, h, hr) {
            return this.t('bookAt').replace('{when}', this.dayName(day.date) + ' ' + day.date + ' ' + Cal.hm(h * 60))
                .replace('{free}', hr.free).replace('{cap}', hr.capacity).replace('{pct}', hr.loadPct);
        },

        cellInner: function (day, h, hr) {
            var lvl = Cal.level(hr.loadPct, {over: hr.over});

            return {lvl: lvl, html: this.bookBtn(day.date, h,
                '<span class="cw-cal__n">' + hr.booked + '/' + hr.capacity + '</span><span class="cw-cal__pct">' + hr.loadPct + ' %</span>' +
                (hr.over ? '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i>' : ''), this.cellAria(day, h, hr))};
        },

        paintWeek: function (d) {
            var self = this, span = Cal.hourSpan(d.days);

            if (!span) { return State.empty({icon: 'fas fa-calendar-xmark', title: this.t('allClosed')}); }

            var head = '<div class="cw-cal__corner"></div>' + d.days.map(function (day) {
                var lvl = Cal.level(day.loadPct, {closed: day.closed, over: day.over});

                return '<div class="cw-cal__dh cw-cal__dh--' + lvl + (day.date === d.today ? ' is-today' : '') + '">' +
                    '<button type="button" class="cw-cal-open-day cw-cal__dhbtn" data-date="' + esc(day.date) + '">' + esc(self.dayName(day.date)) + ' ' + esc(self.dm(day.date)) + '</button>' +
                    '<span class="cw-cal__dpct">' + (day.closed ? esc(self.t('lvl_closed')) : day.cars + ' · ' + day.loadPct + ' %') + '</span></div>';
            }).join('');
            var by = {};

            d.days.forEach(function (day) { by[day.date] = Cal.ordersByHour(d.orders, day.date); });

            var rows = '';

            for (var h = span.from; h < span.to; h++) {
                rows += '<div class="cw-cal__hl">' + Cal.hm(h * 60) + '</div>' + d.days.map(function (day) {
                    var hr = Cal.hourOf(day, h);

                    if (day.closed || !hr) { return '<div class="cw-cal__cell cw-cal__cell--closed" aria-hidden="true"></div>'; }

                    var c = self.cellInner(day, h, hr);

                    return '<div class="cw-cal__cell cw-cal__cell--' + c.lvl + '">' + c.html +
                        '<div class="cw-cal__chips">' + ((by[day.date][h] || []).map(function (o) { return self.chip(o, false); }).join('')) + '</div></div>';
                }).join('');
            }

            return '<div class="cw-cal__scroll"><div class="cw-cal__week" role="group" aria-label="' + esc(this.t('week')) + '">' + head + rows + '</div></div>';
        },

        paintDay: function (d) {
            var self = this, day = d.days[0];

            if (day.closed) { return State.empty({icon: 'fas fa-calendar-xmark', title: this.t('closedDay')}); }

            var by = Cal.ordersByHour(d.orders, day.date);
            var rows = (day.hours || []).map(function (hr) {
                var lvl = Cal.level(hr.loadPct, {over: hr.over});
                var chips = (by[hr.h] || []).map(function (o) { return self.chip(o, true); }).join('');

                return '<tr><th scope="row" class="cw-cal__hl">' + Cal.hm(hr.h * 60) + '</th>' +
                    '<td class="cw-cal__load"><div class="cw-cal__bartrack"><div class="cw-cal__barfill cw-cal__barfill--' + lvl + '" data-w="' + Math.min(100, hr.loadPct) + '"></div></div>' +
                    '<span class="cw-cal__n">' + hr.booked + '/' + hr.capacity + '</span> <span class="cw-cal__pct">' + hr.loadPct + ' %</span></td>' +
                    '<td class="cw-cal__cell cw-cal__cell--' + lvl + '">' + chips + '</td>' +
                    '<td class="cw-cal__act">' + self.bookBtn(day.date, hr.h, '<i class="fas fa-plus" aria-hidden="true"></i> ' + esc(self.t('book')), self.cellAria(day, hr.h, hr)) + '</td></tr>';
            }).join('');

            var facts = '<p class="cw-cal__sum">' + esc(this.t('lines')) + ': <strong>' + day.lines + '</strong> · ' + esc(this.t('washers')) + ': <strong>' + day.washers + '</strong> · ' +
                esc(this.t('dayCap')) + ': <strong>' + day.bookedMinutes + ' / ' + day.capacityMinutes + '</strong> ' + esc(this.t('min')) +
                (day.maxCars ? ' · ' + esc(this.t('maxCars')) + ': <strong>' + day.cars + ' / ' + day.maxCars + '</strong>' : '') +
                (day.full ? ' · <span class="cw-cal__flag">' + esc(this.t('dayFull')) + '</span>' : (day.onlineFull ? ' · <span class="cw-cal__flag">' + esc(this.t('onlineFull')) + '</span>' : '')) + '</p>';

            var $html = facts + '<div class="cw-cal__scroll"><table class="cw-cal__daytbl"><thead><tr><th scope="col">' + esc(this.t('time')) + '</th><th scope="col">' + esc(this.t('load')) +
                '</th><th scope="col">' + esc(this.t('orders')) + '</th><th scope="col"><span class="cw-sr">' + esc(this.t('book')) + '</span></th></tr></thead><tbody>' + rows + '</tbody></table></div>';

            // widths go through the CSSOM (no inline style attribute): applied after the paint
            setTimeout(function () { self.$cal.find('.cw-cal__barfill').each(function () { this.style.width = $(this).data('w') + '%'; }); }, 0);

            return $html;
        },

        paintMonth: function (d) {
            var self = this, g = Cal.monthGrid(this.date), by = {};

            d.days.forEach(function (day) { by[day.date] = day; });

            var head = [1, 2, 3, 4, 5, 6, 0].map(function (i) { return '<div class="cw-cal__mh">' + esc(self.dayName(Cal.addDays('2026-10-04', i))) + '</div>'; }).join('');
            var tiles = g.weeks.map(function (w) {
                return w.map(function (date) {
                    var day = by[date];

                    if (!day) { return '<div class="cw-cal__tile cw-cal__tile--out" aria-hidden="true"></div>'; }

                    var lvl = Cal.level(day.loadPct, {closed: day.closed, over: day.over});
                    var out = date.slice(0, 7) !== g.month;
                    var aria = self.dayName(date) + ' ' + date + ': ' + (day.closed ? self.t('lvl_closed') : day.cars + ' ' + self.t('cars') + ', ' + day.loadPct + ' %');

                    return '<button type="button" class="cw-cal-open-day cw-cal__tile cw-cal__tile--' + lvl + (out ? ' is-out' : '') + (date === d.today ? ' is-today' : '') + '" data-date="' + esc(date) + '" aria-label="' + esc(aria) + '">' +
                        '<span class="cw-cal__dnum">' + parseInt(date.slice(8), 10) + '</span>' +
                        (day.closed ? '<span class="cw-cal__pct">' + esc(self.t('lvl_closed')) + '</span>' :
                            '<span class="cw-cal__pct">' + day.loadPct + ' %</span><span class="cw-cal__cars">' + day.cars + ' ' + esc(self.t('carsShort')) + '</span>') +
                        (day.over ? '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i>' : '') + '</button>';
                }).join('');
            }).join('');

            return '<div class="cw-cal__scroll"><div class="cw-cal__month" role="group" aria-label="' + esc(this.t('month')) + '">' + head + tiles + '</div></div>';
        },

        /**
         * The ordinary (full-page) create form of a Reserved order with the time filled in; saving or cancelling comes back here. The
         * human saves - and, with the right, ticks "Book anyway". (A dialog would show the one-field quick-create layout.)
         */
        book: function (date, minutes) {
            var attrs = {status: 'Reserved', orderDateTime: Cal.localToUtc(date, minutes, this.data.tz)};

            this.remember();
            this.getRouter().dispatch('WashOrder', 'create', {attributes: attrs, returnUrl: '#Calendar', rootUrl: '#Calendar'});
            this.getRouter().navigate('#WashOrder/create', {trigger: false});
        },
    });
});
