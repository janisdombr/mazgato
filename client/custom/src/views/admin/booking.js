/**
 * Administration → Car wash → Online booking: working hours per weekday, slot grid, bays,
 * cars-per-hour cap, buffer, closed days, reminder / no-show timing and the public-form guards
 * (rate limit, fill time, optional Cloudflare Turnstile: public site key + write-only secret).
 * Capacity group: washers (also by weekday), crew per car, the online share of the day, a hard daily cap - and, below the form, the
 * LIVE capacity calculator (service x weekday), fed by Booking/action/capacityPreview, i.e. by the server's own Planner.
 */
define(['custom:views/admin/settings-page', 'custom:lib/state'], function (Dep, State) {

    var esc = State.esc;

    return Dep.extend({

        scope: 'Booking',

        events: Object.assign({}, Dep.prototype.events, {
            // the calculator follows the form: every edit asks the server (the same Planner) for a fresh table
            'input .ap-body input': function () { this.scheduleCalc(); },
            'input .ap-body textarea': function () { this.scheduleCalc(); },
            'change .ap-body select': function () { this.scheduleCalc(); },
            'click .ap-reset-rate': function () {
                Espo.Ajax.postRequest('Booking/action/adminResetRate', {}).then(function () { Espo.Ui.success(this.L('rateReset')); }.bind(this));
            },
        }),

        groups: [
            {title: 'hours', fields: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(function (d) {
                return {key: 'bookingHours' + d, type: 'text', placeholder: '08:00-20:00'};
            }).concat([{key: 'bookingClosedDates', type: 'area'}])},
            {title: 'capacity', fields: [
                {key: 'bookingSlotMinutes', type: 'int', placeholder: '15'}, {key: 'bookingPosts', type: 'int', placeholder: '2'},
                {key: 'bookingMaxCarsPerHour', type: 'int', placeholder: '0'}, {key: 'bookingBufferMinutes', type: 'int', placeholder: '5'},
                {key: 'bookingMinLeadMinutes', type: 'int', placeholder: '60'}, {key: 'bookingHorizonDays', type: 'int', placeholder: '30'},
                {key: 'bookingWashers', type: 'int', placeholder: ''}, {key: 'bookingWashersByWeekday', type: 'text', placeholder: 'Sat=4, Sun=4'},
                {key: 'bookingCrewDefault', type: 'int', placeholder: '1'}, {key: 'bookingDayCapacityPercent', type: 'int', placeholder: '100'},
                {key: 'bookingMaxCarsPerDay', type: 'int', placeholder: '0'},
            ]},
            {title: 'followup', fields: [
                {key: 'bookingReminderHours', type: 'int', placeholder: '3'}, {key: 'bookingNoShowGraceMinutes', type: 'int', placeholder: '30'},
            ]},
            {title: 'guards', fields: [
                {key: 'bookingRatePerHour', type: 'int', placeholder: '10'}, {key: 'bookingMinFillSeconds', type: 'int', placeholder: '2'},
            ]},
            {title: 'turnstile', fields: [
                {key: 'turnstileSiteKey', type: 'text', placeholder: '0x4AAAAAAA…'}, {key: 'turnstileSecret', type: 'secret'},
                {key: 'turnstileHostname', type: 'text'},
            ]},
        ],

        extraTop: function () {
            var url = esc(this.data.siteUrl.replace(/\/$/, '') + '/booking/');

            return '<section class="cw-section"><p><strong>' + esc(this.L('publicLink')) + '</strong> <a href="' + url + '" target="_blank" rel="noopener">' + url + '</a></p>' +
                (this.data.modules.booking ? '' : '<p class="cw-meta">' + esc(this.L('moduleOff')) + '</p>') + '</section>';
        },

        afterPaint: function () { this.calc(); },

        scheduleCalc: function () {
            clearTimeout(this.calcTimer);
            this.calcTimer = setTimeout(this.calc.bind(this), 350);
        },

        calc: function () {
            var self = this, seq = this.calcSeq = (this.calcSeq || 0) + 1;

            if (!this.$el.find('.cp-calc').length) { return; }

            Espo.Ajax.postRequest('Booking/action/capacityPreview', {values: this.collect()}).then(function (r) {
                if (seq === self.calcSeq) { self.$el.find('.cp-calc').html(self.calcHtml(r)); }
            }).catch(function () {
                if (seq === self.calcSeq) { self.$el.find('.cp-calc').html('<p class="cw-meta" role="alert">' + esc(self.L('calcError')) + '</p>'); }
            });
        },

        /** Renders what the server computed; no capacity formula lives here. */
        calcHtml: function (r) {
            var self = this, days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            var warn = function (codes) {
                return (codes || []).map(function (c) {
                    var t = self.L('warn_' + c);
                    return '<li>' + esc(t) + '</li>';
                }).join('');
            };
            var head = '<tr><th scope="col">' + esc(this.L('calcService')) + '</th>' + days.map(function (d) {
                return '<th scope="col">' + esc(self.L(d)) + '</th>';
            }).join('') + '</tr>';
            var cap = '<tr class="cp-calc__day"><th scope="row">' + esc(this.L('calcDayRow')) + '</th>' + r.weekdays.map(function (w) {
                return '<td>' + (w.closed ? esc(self.L('calcClosed')) : esc(w.capacityMinutes) + '<div class="cw-meta">' + esc(w.washers) + ' · ' + esc(w.lines) + ' ' + esc(self.L('calcLines')) + '</div>') + '</td>';
            }).join('') + '</tr>';
            var all = [];
            var body = r.services.map(function (s) {
                var sw = (s.warnings || []).slice();
                var first = '<th scope="row">' + esc(s.name) + '<div class="cw-meta">' + esc(s.minutes || '30') + ' ' + esc(self.L('calcMinutes')) + ' · ' + esc(s.crew) + ' ' + esc(self.L('calcCrew')) + '</div></th>';
                var cells = days.map(function (d) {
                    var c = s.days[d];
                    (c.warnings || []).forEach(function (x) { if (sw.indexOf(x) < 0) { sw.push(x); } });

                    if (c.closed) { return '<td class="is-closed">' + esc(self.L('calcClosed')) + '</td>'; }

                    return '<td class="' + ((c.warnings || []).length ? 'has-warning' : '') + '"><strong>' + esc(c.perDay) + '</strong> ' + esc(self.L('calcCars')) +
                        '<div class="cw-meta">' + esc(c.perHour) + ' ' + esc(self.L('calcPerHour')) + ' · ' + esc(c.lines) + ' ' + esc(self.L('calcLines')) + '</div>' +
                        '<div class="cw-meta">' + esc(self.L('calcOnline')) + ' ' + esc(c.online) + '</div></td>';
                }).join('');
                sw.forEach(function (x) { if (all.indexOf(x) < 0) { all.push(x); } });

                return '<tr>' + first + cells + '</tr>';
            }).join('');

            (r.warnings || []).forEach(function (x) { if (all.indexOf(x) < 0) { all.unshift(x); } });

            return (r.services.length ? '<div class="cp-calc__wrap"><table class="cp-calc__table">' + head + cap + body + '</table></div>' : '<p class="cw-meta">' + esc(this.L('calcNone')) + '</p>') +
                (all.length ? '<div class="cp-calc__warn" role="status"><strong>' + esc(this.L('calcWarnings')) + '</strong><ul>' + warn(all) + '</ul></div>' : '');
        },

        extraBottom: function () {
            return '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('calcTitle')) + '</h3><p class="cw-meta">' + esc(this.L('calcIntro')) + '</p><div class="cp-calc" aria-live="polite"></div></section>' +
                '<section class="cw-section"><button type="button" class="cw-btn ap-reset-rate">' + esc(this.L('resetRate')) + '</button></section>';
        },
    });
});
