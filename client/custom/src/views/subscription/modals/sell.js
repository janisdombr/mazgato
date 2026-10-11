/** Dialog: sell a subscription (pack of washes / unlimited for a period). Server: Subscription/action/sell. */
define(['views/modal'], function (Dep) {

    var esc = function (v) { return $('<div>').text(v == null ? '' : v).html(); };

    return Dep.extend({

        backdrop: true,

        templateContent: '<div class="sell-sub cw-pad"></div>',

        setup: function () {
            this.headerText = this.T('sellTitle');
            this.buttonList = [
                {name: 'sell', label: 'Save', style: 'primary', onClick: function () { this.sell(); }.bind(this)},
                {name: 'cancel', label: 'Cancel'},
            ];
        },

        T: function (k) { return this.translate(k, 'labels', 'Subscription'); },

        afterRender: function () {
            var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
            var today = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
            var f = function (label, html) { return '<div class="cw-field"><label>' + esc(label) + '</label>' + html + '</div>'; };

            this.$el.find('.sell-sub').html(
                '<p class="cw-meta">' + esc(this.T('sellHint')) + '</p>' +
                (this.options.customerId
                    ? '<p><strong>' + esc(this.options.customerName || '') + '</strong></p>'
                    : f(this.translate('Customer', 'scopeNames'), '<input class="form-control s-customer" placeholder="Customer id">')) +
                '<div class="cw-fields">' +
                f(this.translate('kind', 'fields', 'Subscription'), '<select class="form-control s-kind"><option value="Pack">' + esc(this.getLanguage().translateOption('Pack', 'kind', 'Subscription')) +
                    '</option><option value="Unlimited">' + esc(this.getLanguage().translateOption('Unlimited', 'kind', 'Subscription')) + '</option></select>') +
                f(this.T('washes'), '<input type="number" min="1" class="form-control s-washes" value="10">') +
                f(this.translate('validFrom', 'fields', 'Subscription'), '<input type="date" class="form-control s-from" value="' + today + '">') +
                f(this.translate('validTo', 'fields', 'Subscription'), '<input type="date" class="form-control s-to">') +
                f(this.translate('price', 'fields', 'Subscription') + ' (EUR)', '<input type="number" min="0" step="0.01" class="form-control s-price">') +
                f(this.translate('paymentMethod', 'fields', 'WashOrder'), '<select class="form-control s-method"><option value="Cash">' + esc(this.getLanguage().translateOption('Cash', 'paymentMethod', 'WashOrder')) +
                    '</option><option value="Card">' + esc(this.getLanguage().translateOption('Card', 'paymentMethod', 'WashOrder')) + '</option><option value="Invoice">' + esc(this.getLanguage().translateOption('Invoice', 'paymentMethod', 'WashOrder')) + '</option></select>') +
                '<div class="cw-field cw-field--check"><input type="checkbox" class="s-paid" id="s-paid"> <label for="s-paid">' + esc(this.T('paid')) + '</label></div></div>'
            );

            this.$el.on('change', '.s-kind', function (e) { this.$el.find('.s-washes').closest('.cw-field').toggle($(e.currentTarget).val() === 'Pack'); }.bind(this));
        },

        sell: function () {
            var $e = this.$el, kind = $e.find('.s-kind').val();
            var body = {
                customerId: this.options.customerId || $e.find('.s-customer').val(), kind: kind,
                washes: kind === 'Pack' ? parseInt($e.find('.s-washes').val(), 10) || 0 : null,
                validFrom: $e.find('.s-from').val() || null, validTo: $e.find('.s-to').val() || null,
                price: $e.find('.s-price').val() === '' ? null : parseFloat($e.find('.s-price').val()),
                paymentMethod: $e.find('.s-method').val(), paid: $e.find('.s-paid').is(':checked'),
            };

            this.disableButton('sell');
            Espo.Ajax.postRequest('Subscription/action/sell', body).then(function (r) {
                Espo.Ui.success(this.T('sold'));
                this.trigger('sold', r);
                this.close();
            }.bind(this)).catch(function () { this.enableButton('sell'); }.bind(this));
        },
    });
});
