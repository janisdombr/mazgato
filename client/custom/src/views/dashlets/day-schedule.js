/**
 * Day schedule: a grid of hours x bays with every order of the chosen day, busy cells shaded,
 * click an order to open it. Reserved cards carry Start and No-show. All numbers (bays, hours,
 * durations) come from Booking/action/day; the view only lays them out.
 */
define(['custom:views/dashlets/base'], function (Dep) {

    return Dep.extend({

        name: 'DaySchedule',

        autoFit: true,

        templateContent: '<div class="cw-pad"><div class="ds-body" data-cw-fit></div></div>',

        events: {
            'click .ds-prev': function () { this.shift(-1); },
            'click .ds-next': function () { this.shift(1); },
            'click .ds-today': function () { this.date = null; this.load(); },
            'click .ds-start': function (e) { this.act('Booking/action/start', $(e.currentTarget)); },
            'click .ds-noshow': function (e) { this.act('Booking/action/noShow', $(e.currentTarget)); },
        },

        afterRender: function () { this.load(); },

        actionRefresh: function () { this.load(); },

        t: function (n) { return this.translate(n, 'labels', 'DaySchedule'); },

        esc: function (v) { return this.cwState.esc(v); },

        shift: function (n) {
            var d = new Date((this.date || this.data.date) + 'T00:00:00');
            d.setDate(d.getDate() + n);
            this.date = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
            this.load();
        },

        load: function () {
            var $b = this.$el.find('.ds-body');

            if (!this.data) { this.cwShowLoading($b); }

            Espo.Ajax.postRequest('Booking/action/day', this.date ? {date: this.date} : {}).then(function (d) {
                this.data = d;
                this.paint(d);
            }.bind(this)).catch(function (xhr) {
                if (this.data && !this.cwState.isAuthError(xhr)) { return; }
                this.data = null;
                this.cwShowError($b, xhr, this.load.bind(this));
            }.bind(this));
        },

        act: function (url, $b) {
            if ($b.hasClass('is-busy')) { return; }
            $b.addClass('is-busy');
            Espo.Ajax.postRequest(url, {id: $b.data('id')}).then(this.load.bind(this)).catch(function () { $b.removeClass('is-busy'); });
        },

        hm: function (min) { return ('0' + Math.floor(min / 60)).slice(-2) + ':' + ('0' + (min % 60)).slice(-2); },

        paint: function (d) {
            var $b = this.$el.find('.ds-body');
            var nav = '<div class="cw-sched__nav"><button type="button" class="cw-btn ds-prev" aria-label="' + this.esc(this.t('prev')) + '"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>' +
                '<strong class="cw-sched__date">' + this.esc(d.date) + '</strong>' +
                '<button type="button" class="cw-btn ds-next" aria-label="' + this.esc(this.t('next')) + '"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>' +
                '<button type="button" class="cw-btn ds-today">' + this.esc(this.t('today')) + '</button></div>';

            if (d.open == null) {
                $b.html(nav + this.cwState.empty({icon: 'fas fa-calendar-xmark', title: this.t('closed')}));
                return;
            }

            var posts = d.posts, hours = [];
            var first = Math.floor(d.open / 60), last = Math.ceil(d.close / 60);
            for (var h = first; h < last; h++) { hours.push(h); }

            var self = this;
            var cols = [];
            for (var p = 1; p <= posts; p++) { cols.push(p); }
            var hasLoose = d.rows.some(function (r) { return !r.post; });
            if (hasLoose) { cols.push(0); }

            // busy[post][hour] = true when an order that started earlier still covers that hour
            var busy = {};
            d.rows.forEach(function (r) {
                var post = r.post || 0, from = Math.floor(r.startMin / 60), to = Math.ceil((r.startMin + r.minutes) / 60);
                for (var x = from + 1; x < to; x++) { busy[post + ':' + x] = true; }
            });

            var th = '<tr><th class="cw-sched__h"></th>' + cols.map(function (c) {
                return '<th scope="col">' + self.esc(c ? self.t('bay') + ' ' + c : '—') + '</th>';
            }).join('') + '</tr>';

            var body = hours.map(function (h) {
                return '<tr><th scope="row" class="cw-sched__h">' + self.hm(h * 60) + '</th>' + cols.map(function (c) {
                    var cell = d.rows.filter(function (r) { return (r.post || 0) === c && Math.floor(r.startMin / 60) === h; }).map(self.cardHtml.bind(self)).join('');
                    return '<td class="' + (busy[c + ':' + h] ? 'is-busy' : '') + '">' + cell + '</td>';
                }).join('') + '</tr>';
            }).join('');

            $b.html(nav + (d.rows.length ? '' : '<p class="cw-meta">' + this.esc(this.t('empty')) + '</p>') +
                '<div class="cw-sched__wrap"><table class="cw-sched" aria-label="' + this.esc(this.t('title')) + '">' + th + body + '</table></div>');
            this.fitToContent();
        },

        cardHtml: function (r) {
            var chip = {'Reserved': 'reserved', 'New': 'wait', 'In Progress': 'progress', 'Completed': 'done'}[r.status] || 'cancel';
            var acts = r.status === 'Reserved'
                ? '<button type="button" class="cw-btn cw-btn--primary ds-start" data-id="' + r.id + '">' + this.esc(this.t('start')) + '</button>' +
                  '<button type="button" class="cw-btn ds-noshow" data-id="' + r.id + '">' + this.esc(this.t('noShow')) + '</button>'
                : '';

            return '<div class="cw-sched__item cw-sched__item--' + chip + '">' +
                '<a class="cw-plate cw-plate--sm" href="#WashOrder/view/' + r.id + '">' + this.esc(r.plate || r.name) + '</a> ' +
                '<span class="cw-meta">' + this.esc(r.time) + ' · ' + r.minutes + ' ' + this.esc(this.t('min')) + '</span>' +
                '<div class="cw-meta">' + this.esc(r.service) + '</div>' + (acts ? '<div class="cw-actions">' + acts + '</div>' : '') + '</div>';
        },
    });
});
