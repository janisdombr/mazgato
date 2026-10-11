define(['custom:views/dashlets/base'], function (Dep) {

    return Dep.extend({

        name: 'Shifts',

        autoFit: true,

        templateContent: '<div class="cw-pad"><div class="shifts-body" data-cw-fit></div></div>',

        events: {
            'click .action-clock': function (e) {
                this.clock($(e.currentTarget).data('id'));
            },
        },

        afterRender: function () {
            this.list = null;
            this.load();
        },

        // Auto-refresh: reload quietly, keep what is on screen.
        actionRefresh: function () {
            this.load();
        },

        esc: function (v) { return this.cwState.esc(v); },

        t: function (name) { return this.translate(name, 'labels', 'Shifts'); },

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

        load: function () {
            var $body = this.$el.find('.shifts-body');

            if (!this.list) { this.cwShowLoading($body); }

            // Only hired staff can clock in — people on leave or dismissed are not shown.
            Espo.Ajax.getRequest('Employee', {
                maxSize: 60,
                orderBy: 'name',
                order: 'asc',
                where: [{type: 'equals', attribute: 'status', value: 'Hired'}],
            }).then(function (data) {
                this.list = data.list || [];
                this.renderChips(this.list);
            }.bind(this)).catch(function (xhr) {
                if (this.list && !this.cwState.isAuthError(xhr)) { return; }
                this.list = null;
                this.cwShowError($body, xhr, this.load.bind(this));
            }.bind(this));
        },

        // Chips, not a table: a toggle per person, 40 px tall (D7).
        renderChips: function (list) {
            var $body = this.$el.find('.shifts-body');

            if (!list.length) {
                $body.html(this.cwState.empty({icon: 'fas fa-user-clock', title: this.t('noEmployees')}));
                this.fitToContent();
                return;
            }

            var chips = list.map(function (e) {
                var since = this.fmtTime(e.shiftStartedAt);
                var label = e.onShift
                    ? '<i class="fas fa-stop" aria-hidden="true"></i> ' + this.esc(this.t('out'))
                    : '<i class="fas fa-play" aria-hidden="true"></i> ' + this.esc(this.t('in'));

                return '<div class="cw-shift' + (e.onShift ? ' is-on' : '') + '">' +
                    '<span class="cw-shift__dot" aria-hidden="true"></span>' +
                    '<span class="cw-strong">' + this.esc(e.name) + '</span>' +
                    (e.onShift ? '<span class="cw-meta cw-num">' + this.esc(this.t('onShift') + ' ' + since) + '</span>' : '') +
                    '<button type="button" class="cw-btn action-clock" data-id="' + e.id + '">' + label + '</button>' +
                '</div>';
            }.bind(this)).join('');

            $body.html('<div class="cw-shiftrow">' + chips + '</div>');
            this.fitToContent();
        },

        clock: function (id) {
            if (!id) { return; }

            Espo.Ajax.postRequest('CarWash/action/clock', {employeeId: id})
                .then(function (res) {
                    var msg = res.onShift
                        ? this.t('clockedIn')
                        : this.t('clockedOut') + (res.hours != null ? ' — ' + res.hours + ' ' + this.t('hoursShort') : '');

                    Espo.Ui.success(msg);
                    this.load();
                }.bind(this));
        },

    });
});
