/**
 * Goods receipt dialog: supplier, invoice number, date, lines (supply, quantity, unit price). "Post" calls
 * Inventory/action/receive (create + post in one transaction); the rules - stock up once, weighted-average cost,
 * journal - are server-side (Tools/Inventory). A per-dialog `clientKey` makes a double click / retry harmless.
 */
define(['views/modal'], function (Dep) {

    return Dep.extend({

        backdrop: true,
        className: 'dialog dialog-record',

        templateContent: `
            <div class="supply-receipt">
                <div class="row" style="margin-bottom:10px;">
                    <div class="col-sm-5"><label class="control-label">{{translate 'supplier' category='labels' scope='Supply'}}</label>
                        <input type="text" class="form-control field-supplier" maxlength="120"></div>
                    <div class="col-sm-4"><label class="control-label">{{translate 'invoiceNumber' category='labels' scope='Supply'}}</label>
                        <input type="text" class="form-control field-invoice" maxlength="60"></div>
                    <div class="col-sm-3"><label class="control-label">{{translate 'receiptDate' category='labels' scope='Supply'}}</label>
                        <input type="date" class="form-control field-date"></div>
                </div>
                <table class="table table-condensed">
                    <thead><tr>
                        <th>{{translate 'Supply' category='scopeNames'}}</th>
                        <th style="width:130px;">{{translate 'qtyCol' category='labels' scope='Supply'}}</th>
                        <th style="width:150px;">{{translate 'priceCol' category='labels' scope='Supply'}}</th>
                        <th style="width:110px;">{{translate 'sumCol' category='labels' scope='Supply'}}</th>
                        <th style="width:44px;"></th>
                    </tr></thead>
                    <tbody class="receipt-lines"></tbody>
                </table>
                <button type="button" class="btn btn-default action-add-line">{{translate 'addLine' category='labels' scope='Supply'}}</button>
                <div class="pull-right"><strong>{{translate 'receiptTotal' category='labels' scope='Supply'}}: <span class="receipt-total">0,00</span> €</strong></div>
                <p class="text-muted" style="margin-top:12px;">{{translate 'costHint' category='labels' scope='Supply'}}</p>
                <div class="text-danger receipt-error" style="display:none;"></div>
            </div>
        `,

        events: {
            'click .action-add-line': function () { this.addLine(); },
            'click .action-remove-line': function (e) {
                $(e.currentTarget).closest('tr').remove();
                this.recalc();
            },
            'input .line-qty': function () { this.recalc(); },
            'input .line-price': function () { this.recalc(); },
        },

        setup: function () {
            this.headerText = this.translate('receiptHeader', 'labels', 'Supply');
            this.clientKey = 'rc-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
            this.supplies = [];

            this.buttonList = [
                {name: 'post', label: this.translate('postReceipt', 'labels', 'Supply'), style: 'primary', onClick: this.post.bind(this)},
                {name: 'cancel', label: 'Close'},
            ];
        },

        afterRender: function () {
            var d = new Date();
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };

            this.$el.find('.field-date').val(d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()));

            Espo.Ajax.postRequest('Inventory/action/sheet', {}).then(function (res) {
                this.supplies = (res && res.list) || [];
                this.addLine();
            }.bind(this));
        },

        esc: function (v) { return $('<div>').text(v == null ? '' : String(v)).html(); },

        addLine: function () {
            var opts = '<option value="">' + this.esc(this.translate('chooseSupply', 'labels', 'Supply')) + '</option>' +
                this.supplies.map(function (s) {
                    return '<option value="' + this.esc(s.id) + '">' + this.esc(s.name) + ' (' + this.esc(s.unit) + ')</option>';
                }.bind(this)).join('');

            this.$el.find('.receipt-lines').append(
                '<tr><td><select class="form-control line-supply">' + opts + '</select></td>' +
                '<td><input type="number" class="form-control line-qty" min="0" step="any"></td>' +
                '<td><input type="number" class="form-control line-price" min="0" step="any"></td>' +
                '<td class="line-sum text-right">0,00</td>' +
                '<td><button type="button" class="btn btn-link action-remove-line" title="' + this.esc(this.translate('removeLine', 'labels', 'Supply')) + '">&times;</button></td></tr>'
            );
        },

        money: function (n) {
            return (Math.round(n * 100) / 100).toFixed(2).replace('.', ',');
        },

        recalc: function () {
            var total = 0;

            this.$el.find('.receipt-lines tr').each(function (i, tr) {
                var $tr = $(tr);
                var sum = (parseFloat($tr.find('.line-qty').val()) || 0) * (parseFloat($tr.find('.line-price').val()) || 0);

                total += sum;
                $tr.find('.line-sum').text(this.money(sum));
            }.bind(this));

            this.$el.find('.receipt-total').text(this.money(total));
        },

        showError: function (text) {
            this.$el.find('.receipt-error').text(text).show();
        },

        post: function () {
            var lines = [];

            this.$el.find('.receipt-lines tr').each(function (i, tr) {
                var $tr = $(tr);
                var supplyId = $tr.find('.line-supply').val();
                var qty = parseFloat($tr.find('.line-qty').val());
                var price = $tr.find('.line-price').val();

                if (!supplyId && !qty) { return; }

                lines.push({supplyId: supplyId, quantity: qty, unitPrice: price === '' ? null : parseFloat(price)});
            });

            if (!lines.length || lines.some(function (l) { return !l.supplyId || !(l.quantity > 0); })) {
                this.showError(this.translate('noLines', 'labels', 'Supply'));

                return;
            }

            this.disableButton('post');

            Espo.Ajax.postRequest('Inventory/action/receive', {
                clientKey: this.clientKey,
                supplier: this.$el.find('.field-supplier').val(),
                invoiceNumber: this.$el.find('.field-invoice').val(),
                receiptDate: this.$el.find('.field-date').val(),
                lines: lines,
            }).then(function (res) {
                Espo.Ui.success(this.translate('receiptPosted', 'labels', 'Supply') + (res && res.name ? ': ' + res.name : ''));

                if (this.options.onDone) { this.options.onDone(); }

                this.close();
            }.bind(this)).catch(function () {
                this.enableButton('post');
                this.showError(this.translate('failed', 'labels', 'Supply'));
            }.bind(this));
        },
    });
});
