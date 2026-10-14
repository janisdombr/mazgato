define(['custom:views/dashlets/base', 'custom:lib/period'], function (Dep, Period) {

    return Dep.extend({

        name: 'Payroll',

        autoFit: true,

        templateContent: '<div class="payroll cw-pad"><div class="payroll-body" data-cw-fit></div></div>',

        afterRender: function () {
            if (this.unsubscribe) { this.unsubscribe(); }

            // The period is the Statistics tab's single switch (custom:lib/period).
            this.unsubscribe = Period.subscribe(function () { this.load(); }.bind(this));
            this.loadTranslations().then(function () { this.load(); }.bind(this));
        },

        onRemove: function () {
            if (this.unsubscribe) { this.unsubscribe(); this.unsubscribe = null; }
        },

        actionRefresh: function () { this.load(); },

        // Local calendar parts — toISOString() would shift the day for Europe/Riga.
        toIso: function (d) {
            var m = d.getMonth() + 1;
            var day = d.getDate();

            return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
        },

        esc: function (value) {
            return this.cwState.esc(value);
        },

        // Role names are data — localize them through the Translation table.
        loadTranslations: function () {
            var lang = this.getPreferences().get('language') || this.getConfig().get('language') || 'lv_LV';
            var field = {lv_LV: 'textLv', ru_RU: 'textRu', en_US: 'textEn', lt_LT: 'textLt'}[lang];

            if (!field) { this.trMap = {}; return Promise.resolve(); }

            return Espo.Ajax.getRequest('Translation', {maxSize: 200, orderBy: 'name'})
                .then(function (data) {
                    var map = {};
                    (data.list || []).forEach(function (r) {
                        if (r.name && r[field]) { map[r.name] = r[field]; }
                    });
                    this.trMap = map;
                }.bind(this))
                .catch(function () { this.trMap = {}; }.bind(this));
        },

        tr: function (text) { return (this.trMap || {})[text] || text; },

        load: function () {
            var payload = Period.payload();

            var $body = this.$el.find('.payroll-body');
            this.cwShowLoading($body);

            var seq = this.seq = (this.seq || 0) + 1;

            Espo.Ajax.postRequest('CarWash/action/payroll', payload)
                .then(function (res) {
                    if (seq !== this.seq) { return; }
                    this.renderTable(res || {});
                }.bind(this))
                .catch(function (xhr) { this.cwShowError($body, xhr, this.load.bind(this)); }.bind(this));
        },

        renderTable: function (res) {
            var t = function (name) {
                return this.translate(name, 'labels', 'Payroll');
            }.bind(this);

            var rows = res.rows || [];

            if (!rows.length) {
                this.$el.find('.payroll-body').html(this.cwState.empty({icon: 'fas fa-wallet', title: t('noData')}));
                return;
            }

            // Role columns are dynamic — roles are editable data, not a fixed enum.
            var roles = res.roles || [];

            var body = rows.map(function (r) {
                var byRole = r.byRole || {};

                var roleCells = roles.map(function (role) {
                    return '<td class="cw-center">' + (byRole[role.id] || '') + '</td>';
                }).join('');

                return '<tr>' +
                    '<td>' + this.esc(r.employeeName) + '</td>' +
                    roleCells +
                    '<td class="cw-center">' + (r.works || 0) + '</td>' +
                    '<td class="cw-right"><b>' + this.money(r.earned || 0) + '</b></td>' +
                '</tr>';
            }.bind(this)).join('');

            var roleHeads = roles.map(function (role) {
                return '<th class="cw-center">' + this.esc(this.tr(role.name)) + '</th>';
            }.bind(this)).join('');

            var html =
                '<table class="cw-table">' +
                    '<thead><tr>' +
                        '<th>' + t('employee') + '</th>' +
                        roleHeads +
                        '<th class="cw-center">' + t('works') + '</th>' +
                        '<th class="cw-right">' + t('earned') + '</th>' +
                    '</tr></thead>' +
                    '<tbody>' + body + '</tbody>' +
                    '<tfoot><tr class="cw-total">' +
                        '<td><b>' + t('total') + '</b></td>' +
                        roles.map(function () { return '<td></td>'; }).join('') +
                        '<td class="cw-center"><b>' + (res.totalWorks || 0) + '</b></td>' +
                        '<td class="cw-right"><b>' + this.money(res.totalEarned || 0) + '</b></td>' +
                    '</tr></tfoot>' +
                '</table>';

            this.$el.find('.payroll-body').html(html);
        },

    });
});
