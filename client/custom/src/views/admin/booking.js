/**
 * Administration → Car wash → Online booking: working hours per weekday, slot grid, bays,
 * cars-per-hour cap, buffer, closed days, reminder / no-show timing and the public-form guards
 * (rate limit, fill time, optional Cloudflare Turnstile: public site key + write-only secret).
 */
define(['custom:views/admin/settings-page', 'custom:lib/state'], function (Dep, State) {

    var esc = State.esc;

    return Dep.extend({

        scope: 'Booking',

        events: Object.assign({}, Dep.prototype.events, {
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

        extraBottom: function () {
            return '<section class="cw-section"><button type="button" class="cw-btn ap-reset-rate">' + esc(this.L('resetRate')) + '</button></section>';
        },
    });
});
