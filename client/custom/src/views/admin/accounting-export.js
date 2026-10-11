/**
 * Administration → Car wash → Accounting export: the sales journal of COMPLETED orders for a period,
 * as CSV or XML for the accountant (CarWash/action/accountingExport). Preview first (totals by payment
 * method, orders still waiting for a till receipt), then Download. One honest ledger: the same orders the
 * Statistics dashlet counts as revenue. Not an import file of a specific accounting program (docs/FISCAL.md).
 */
define(['view', 'custom:lib/state', 'custom:lib/format'], function (View, State, Fmt) {

    var esc = State.esc;

    function ymd(d) {
        var p = function (n) { return (n < 10 ? '0' : '') + n; };

        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }

    return View.extend({

        templateContent: '<div class="ap-body cw-pad"></div>',

        events: {
            'click .ax-preview': function () { this.run(true); },
            'click .ax-download': function () { this.run(false); },
        },

        L: function (key, section) { return this.translate(key, section || 'labels', 'AccountingExport'); },

        afterRender: function () {
            var now = new Date(), from = new Date(now.getFullYear(), now.getMonth() - 1, 1), to = new Date(now.getFullYear(), now.getMonth(), 0);

            this.$el.find('.ap-body').html(
                '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('title')) + '</h3>' +
                '<p class="cw-meta">' + esc(this.L('intro')) + '</p><div class="cw-fields">' +
                this.input('dateFrom', 'date', ymd(from)) + this.input('dateTo', 'date', ymd(to)) +
                this.select('format', ['csv', 'xml']) + this.select('delimiter', [';', ',']) + this.select('decimal', ['.', ',']) +
                '<div class="cw-field cw-field--check"><input type="checkbox" id="ax-lines" class="ax-lines"><label for="ax-lines">' + esc(this.L('lines')) + '</label></div>' +
                '</div><div class="cw-actions"><button type="button" class="cw-btn ax-preview">' + esc(this.L('preview')) + '</button> ' +
                '<button type="button" class="cw-btn cw-btn--primary ax-download">' + esc(this.L('download')) + '</button></div></section>' +
                '<section class="cw-section ax-result"></section>' +
                '<section class="cw-section"><p class="cw-meta">' + esc(this.L('mapping')) + '</p></section>');
        },

        input: function (name, type, value) {
            return '<div class="cw-field"><label for="ax-' + name + '">' + esc(this.L(name)) + '</label><input type="' + type + '" id="ax-' + name + '" class="form-control ax-' + name + '" value="' + esc(value) + '"></div>';
        },

        select: function (name, opts) {
            return '<div class="cw-field"><label for="ax-' + name + '">' + esc(this.L(name)) + '</label><select id="ax-' + name + '" class="form-control ax-' + name + '">' +
                opts.map(function (o) { return '<option value="' + esc(o) + '">' + esc(o) + '</option>'; }).join('') + '</select></div>';
        },

        payload: function (preview) {
            var $e = this.$el;

            return {
                dateFrom: $e.find('.ax-dateFrom').val(), dateTo: $e.find('.ax-dateTo').val(), format: $e.find('.ax-format').val(),
                delimiter: $e.find('.ax-delimiter').val(), decimal: $e.find('.ax-decimal').val(), lines: $e.find('.ax-lines').is(':checked'), preview: preview,
            };
        },

        run: function (preview) {
            var $r = this.$el.find('.ax-result');

            $r.html(State.loading(this.L('loading')));
            Espo.Ajax.postRequest('CarWash/action/accountingExport', this.payload(preview)).then(function (d) {
                if (preview) { return this.paintPreview(d); }

                var blob = new Blob([d.content], {type: d.mime + ';charset=utf-8'}), a = document.createElement('a');

                a.href = URL.createObjectURL(blob);
                a.download = d.filename;
                document.body.appendChild(a);
                a.click();
                setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
                $r.html('<p>' + esc(this.L('downloaded')) + ': <strong>' + esc(d.filename) + '</strong> (' + esc(d.rowCount) + ')</p>');
            }.bind(this)).catch(function () {
                $r.html(State.error({title: this.L('error'), retryLabel: this.L('retry')}));
            }.bind(this));
        },

        paintPreview: function (d) {
            var money = function (v) { return Fmt.fmtMoney ? Fmt.fmtMoney(v) : String(v); };
            var h = '<p><strong>' + esc(d.orders) + '</strong> ' + esc(this.L('orders')) + ' · ' + esc(this.L('gross')) + ' <strong>' + esc(money(d.totals.gross)) +
                '</strong> · ' + esc(this.L('net')) + ' ' + esc(money(d.totals.net)) + ' · PVN ' + esc(money(d.totals.vat)) + '</p>';

            h += '<p class="cw-meta">' + Object.keys(d.byMethod).map(function (k) { return esc(k) + ': ' + esc(money(d.byMethod[k])); }).join(' · ') + '</p>';

            if (d.fiscalPending > 0) {
                h += '<p class="cw-meta">' + esc(this.L('fiscalPending')) + ': <strong>' + esc(d.fiscalPending) + '</strong></p>';
            }

            if (!d.rows.length) {
                this.$el.find('.ax-result').html(h + '<p>' + esc(this.L('empty')) + '</p>');

                return;
            }

            h += '<div style="overflow:auto"><table class="table"><thead><tr>' + d.columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') +
                '</tr></thead><tbody>' + d.rows.map(function (r) {
                    return '<tr>' + d.columns.map(function (c) { return '<td>' + esc(r[c] || '') + '</td>'; }).join('') + '</tr>';
                }).join('') + '</tbody></table></div>';

            this.$el.find('.ax-result').html(h);
        },
    });
});
