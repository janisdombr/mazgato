/**
 * Administration → Car wash → Accounting integration: the program (Merit Aktiva / SmartAccounts), write-only credentials,
 * mode (manual / automatic / batch), the first-export date, the mapping to the program, plus the queue: test connection,
 * send now, queue a period, send the failed ones again and a reconciliation of a period (failed / never queued / changed
 * after sending). Settings are saved with AccountingSync/action/adminSave; a secret is never sent to the browser.
 */
define(['custom:views/admin/settings-page', 'custom:lib/state'], function (Dep, State) {

    var esc = State.esc;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    return Dep.extend({

        scope: 'AccountingIntegration',

        api: 'AccountingSync/action/',

        events: Object.assign({}, Dep.prototype.events, {
            'click .ac-test': function () { this.test(); },
            'click .ac-run': function () { this.act('run', {}, 'runResult'); },
            'click .ac-enqueue': function () { this.act('enqueue', this.period(), 'queued'); },
            'click .ac-resend-all': function () { this.act('resend', {all: true}, 'requeued'); },
            'click .ac-resend': function (e) { this.act('resend', {id: $(e.currentTarget).data('id')}, 'requeued'); },
            'click .ac-summary': function () { this.summary(); },
        }),

        groups: [
            {title: 'general', fields: [
                {key: 'accountingProvider', type: 'select', options: ['off', 'merit', 'smartaccounts']},
                {key: 'accountingMode', type: 'select', options: ['manual', 'auto', 'batch']},
                {key: 'accountingStartDate', type: 'text', placeholder: 'YYYY-MM-DD'},
                {key: 'accountingBatchTime', type: 'text', placeholder: '23:00'},
            ]},
            {title: 'merit', fields: [
                {key: 'accountingMeritHost', type: 'select', options: ['ee', 'pl']},
                {key: 'accountingMeritId', type: 'secret'}, {key: 'accountingMeritKey', type: 'secret'},
                {key: 'accountingMeritIban', type: 'text'},
            ]},
            {title: 'smart', fields: [
                {key: 'accountingSmartId', type: 'secret'}, {key: 'accountingSmartKey', type: 'secret'},
            ]},
            {title: 'mapping', fields: [
                {key: 'accountingRetailName', type: 'text', placeholder: 'Roku mazgāšana'},
                {key: 'accountingItemCodeBase', type: 'text', placeholder: 'WASH'}, {key: 'accountingItemCodeAddon', type: 'text', placeholder: 'ADDON'},
                {key: 'accountingNumberPrefix', type: 'text'}, {key: 'accountingDueDays', type: 'int', placeholder: '14'},
                {key: 'accountingVatId21', type: 'text'}, {key: 'accountingVatId0', type: 'text'},
                {key: 'accountingPayMethodCash', type: 'text'}, {key: 'accountingPayMethodCard', type: 'text'},
                {key: 'accountingPayAccountCash', type: 'text'}, {key: 'accountingPayAccountCard', type: 'text'},
            ]},
        ],

        fmt: function (key, vars) {
            var t = this.L(key);

            Object.keys(vars || {}).forEach(function (k) { t = t.replace('{' + k + '}', vars[k]); });

            return t;
        },

        period: function () {
            return {dateFrom: this.$el.find('.ac-from').val(), dateTo: this.$el.find('.ac-to').val()};
        },

        extraTop: function () {
            var h = '<section class="cw-section"><p class="cw-meta">' + esc(this.L('intro')) + '</p>';

            if (this.data.testMode) { h += '<p><strong>' + esc(this.L('testModeOn')) + '</strong></p>'; }
            if (!this.data.modules.accounting) { h += '<p class="cw-meta">' + esc(this.L('moduleOff')) + '</p>'; }

            h += '<p class="cw-meta">' + esc(this.L('verifyNote')) + '</p></section>';

            return h;
        },

        extraBottom: function () {
            var now = new Date(), first = new Date(now.getFullYear(), now.getMonth(), 1);

            return '<section class="cw-section"><div class="cw-actions">' +
                '<button type="button" class="cw-btn ac-test">' + esc(this.L('test')) + '</button> ' +
                '<button type="button" class="cw-btn ac-run">' + esc(this.L('run')) + '</button> ' +
                '<button type="button" class="cw-btn ac-resend-all">' + esc(this.L('resendAll')) + '</button></div>' +
                '<div class="cw-fields"><div class="cw-field"><label>' + esc(this.L('dateFrom')) + '</label><input type="date" class="form-control ac-from" value="' + ymd(first) + '"></div>' +
                '<div class="cw-field"><label>' + esc(this.L('dateTo')) + '</label><input type="date" class="form-control ac-to" value="' + ymd(now) + '"></div></div>' +
                '<div class="cw-actions"><button type="button" class="cw-btn ac-enqueue">' + esc(this.L('enqueue')) + '</button> ' +
                '<button type="button" class="cw-btn ac-summary">' + esc(this.L('summary')) + '</button> ' +
                '<a href="#AccountingSync">' + esc(this.L('listLink')) + '</a></div>' +
                '<div class="ac-result" aria-live="polite"></div></section>';
        },

        result: function (html) { this.$el.find('.ac-result').html(html); },

        test: function () {
            if (this.data.values.accountingProvider == null) { Espo.Ui.warning(this.L('noProvider')); }

            Espo.Ajax.postRequest(this.api + 'adminTest', {}).then(function (r) {
                if (r.ok) { Espo.Ui.success(this.L('testOk')); }
                else { Espo.Ui.error(this.L('testFail') + ': ' + r.message); }
            }.bind(this)).catch(function () {});
        },

        act: function (action, body, msgKey) {
            Espo.Ajax.postRequest(this.api + action, body).then(function (r) {
                Espo.Ui.success(this.fmt(msgKey, {sent: r.sent, failed: r.failed, skipped: r.skipped, pending: r.pending, n: r.queued != null ? r.queued : r.requeued}));
                this.summary();
            }.bind(this)).catch(function () {});
        },

        table: function (title, head, rows) {
            if (!rows.length) { return '<h4>' + esc(title) + '</h4><p class="cw-meta">' + esc(this.L('none')) + '</p>'; }

            return '<h4>' + esc(title) + '</h4><table class="table"><thead><tr>' + head.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>' +
                rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table>';
        },

        summary: function () {
            var self = this;

            Espo.Ajax.postRequest(this.api + 'summary', this.period()).then(function (s) {
                var c = s.counts || {};
                var h = '<p><strong>' + esc(self.L('sent')) + ':</strong> ' + (c.Sent || 0) + ' &nbsp; <strong>' + esc(self.L('pending')) + ':</strong> ' + (c.Pending || 0) +
                    ' &nbsp; <strong>' + esc(self.L('failedN')) + ':</strong> ' + (c.Failed || 0) + ' &nbsp; <strong>' + esc(self.L('skippedN')) + ':</strong> ' + (c.Skipped || 0) + '</p>' +
                    '<p class="cw-meta">' + esc(self.fmt('totals', {a: s.ordersTotal, b: s.sentTotal})) + '</p>';

                h += self.table(self.L('failedList'), [self.L('order'), self.L('error'), self.L('attempts'), ''], (s.failed || []).map(function (f) {
                    return [esc(f.order), esc(f.error), esc(f.attempts), '<button type="button" class="btn btn-default btn-sm ac-resend" data-id="' + esc(f.id) + '">' + esc(self.L('resend')) + '</button>'];
                }));
                h += self.table(self.L('missingList'), [self.L('order'), self.L('amount')], (s.missing || []).map(function (m) { return [esc(m.order), esc(m.gross)]; }));
                h += self.table(self.L('driftList'), [self.L('order'), self.L('amount'), self.L('sentNow')], (s.drift || []).map(function (m) { return [esc(m.order), esc(m.sent), esc(m.now)]; }));
                self.result(h);
            }).catch(function () {});
        },
    });
});
