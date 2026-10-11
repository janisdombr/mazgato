/**
 * The single period switch of the Statistics tab (D25). Writes the shared period
 * (custom:lib/period); the Statistics, Payroll and Supply Usage dashlets listen to it.
 */
define(['custom:views/dashlets/base', 'custom:lib/period'], function (Dep, Period) {

    return Dep.extend({

        name: 'PeriodBar',

        autoFit: true,

        templateContent: '<div class="cw-pad"><div class="cw-period" data-cw-fit></div></div>',

        events: {
            'click .cw-seg button': function (e) {
                var key = $(e.currentTarget).data('key');

                if (key === 'custom') {
                    var cur = Period.get();
                    Period.set({key: 'custom', from: cur.from, to: cur.to});
                } else {
                    Period.set({key: key});
                }

                this.renderBar();
            },
            'change .cw-period__from, change .cw-period__to': function () {
                var from = this.$el.find('.cw-period__from').val();
                var to = this.$el.find('.cw-period__to').val();

                if (from && to && from <= to) { Period.set({key: 'custom', from: from, to: to}); }
            },
        },

        afterRender: function () {
            this.renderBar();
        },

        t: function (n) { return this.translate(n, 'labels', 'CarWash'); },

        renderBar: function () {
            var cur = Period.get();
            var labels = {today: 'periodToday', week: 'periodWeek', month: 'periodMonth', quarter: 'periodQuarter', custom: 'periodRange'};
            var seg = Period.KEYS.map(function (k) {
                return '<button type="button" data-key="' + k + '" class="' + (cur.key === k ? 'is-on' : '') + '" aria-pressed="' + (cur.key === k) + '">' + this.cwState.esc(this.t(labels[k])) + '</button>';
            }.bind(this)).join('');

            this.$el.find('.cw-period').html(
                '<div class="cw-seg" role="group">' + seg + '</div>' +
                (cur.key === 'custom'
                    ? '<div class="cw-flex"><input type="date" class="form-control cw-period__from" value="' + cur.from + '"> <span>—</span> <input type="date" class="form-control cw-period__to" value="' + cur.to + '"></div>'
                    : '<span class="cw-meta cw-num">' + this.cwState.esc(this.fmtRange(cur)) + '</span>')
            );
            this.fitToContent();
        },

        fmtRange: function (p) {
            try {
                var dt = this.getDateTime();
                return dt.toDisplayDate(p.from) + (p.from === p.to ? '' : ' — ' + dt.toDisplayDate(p.to));
            } catch (e) {
                return p.from + (p.from === p.to ? '' : ' — ' + p.to);
            }
        },
    });
});
