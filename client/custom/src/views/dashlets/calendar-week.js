/**
 * CalendarWeek: a compact strip of the current week - one tile per day with the number of cars and the load % (colour scale shared
 * with #Calendar) - and a link to the full calendar. Numbers come from Booking/action/range (summary mode): the Planner's, not ours.
 */
define(['custom:views/dashlets/base', 'custom:lib/calendar'], function (Dep, Cal) {

    return Dep.extend({

        name: 'CalendarWeek',

        autoFit: true,

        templateContent: '<div class="cw-pad"><div class="cwk-body" data-cw-fit></div></div>',

        events: {
            'click .cwk-day': function (e) { this.open('day', $(e.currentTarget).data('date')); },
            'click .cwk-all': function () { this.open('week', this.data && this.data.today); },
        },

        afterRender: function () { this.load(); },

        actionRefresh: function () { this.load(); },

        t: function (k) { return this.translate(k, 'labels', 'CwCalendar'); },

        esc: function (v) { return this.cwState.esc(v); },

        dayName: function (date) {
            var names = this.getLanguage().get('Global', 'lists', 'dayNamesShort');

            return (names && names[Cal.dow(date)]) || ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][Cal.dow(date)];
        },

        /** Hand the choice to #Calendar (it reads the same sessionStorage key) and go there. */
        open: function (mode, date) {
            try { window.sessionStorage.setItem('cwCalendar', JSON.stringify({mode: mode, date: date})); } catch (e) { /* ignore */ }

            this.getRouter().navigate('#Calendar', {trigger: true});
        },

        load: function () {
            var $b = this.$el.find('.cwk-body');
            var r = Cal.weekRange(this.getDateTime().getToday());

            if (!this.data) { this.cwShowLoading($b); }

            Espo.Ajax.postRequest('Booking/action/range', {from: r.from, to: r.to, summary: true}).then(function (d) {
                this.data = d;
                this.paint(d);
            }.bind(this)).catch(function (xhr) {
                if (this.data && !this.cwState.isAuthError(xhr)) { return; }
                this.data = null;
                this.cwShowError($b, xhr, this.load.bind(this));
            }.bind(this));
        },

        paint: function (d) {
            var self = this, t = Cal.totals(d.days);
            var tiles = d.days.map(function (day) {
                var lvl = Cal.level(day.loadPct, {closed: day.closed, over: day.over});
                var aria = self.dayName(day.date) + ' ' + day.date + ': ' + (day.closed ? self.t('lvl_closed') : day.cars + ' ' + self.t('cars') + ', ' + day.loadPct + ' %');

                return '<button type="button" class="cwk-day cw-cal__tile cw-cal__tile--' + lvl + (day.date === d.today ? ' is-today' : '') + '" data-date="' + self.esc(day.date) + '" aria-label="' + self.esc(aria) + '">' +
                    '<span class="cw-cal__dnum">' + self.esc(self.dayName(day.date)) + ' ' + parseInt(day.date.slice(8), 10) + '</span>' +
                    (day.closed ? '<span class="cw-cal__pct">' + self.esc(self.t('lvl_closed')) + '</span>' :
                        '<span class="cw-cal__pct">' + day.loadPct + ' %</span><span class="cw-cal__cars">' + day.cars + ' ' + self.esc(self.t('carsShort')) + '</span>') +
                    (day.over ? '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i>' : '') + '</button>';
            }).join('');

            this.$el.find('.cwk-body').html(
                '<div class="cw-cal__strip">' + tiles + '</div>' +
                '<p class="cw-cal__sum">' + this.esc(this.t('week')) + ' ' + Cal.weekNumber(d.days[0].date) + ' · ' + this.esc(this.t('cars')) + ': <strong>' + t.cars + '</strong> · ' +
                this.esc(this.t('load')) + ': <strong>' + t.pct + ' %</strong> <button type="button" class="cw-btn cwk-all">' + this.esc(this.t('openCalendar')) + '</button></p>'
            );
            this.fitToContent();
        },
    });
});
