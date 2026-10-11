/**
 * "Услуги и цены этого заказа" — ONE list for the whole order: the base wash (line 0),
 * then the add-ons, then the total. Prices are per-order (OrderAddon rows); editing one
 * here never touches the service catalogue.
 *
 * Editing a price writes the line and re-totals the order's finalPrice, so the number at
 * the top of the order and the list below can never disagree.
 */
define(['views/record/panels/bottom', 'custom:lib/format', 'custom:lib/state'], function (Dep, Fmt, State) {

    return Dep.extend({

        templateContent: `
            <div class="order-lines-body"></div>
        `,

        events: {
            'change .line-price': function (e) { this.savePrice($(e.currentTarget)); },
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            // Any save of the order (add-on ticked, base wash swapped) changes the lines.
            this.listenTo(this.model, 'sync after:save', function () { this.load(); }, this);
        },

        afterRender: function () {
            this.load();
        },

        esc: function (v) { return State.esc(v); },

        money: function (v) { return Fmt.fmtMoney(v, this.getPreferences().get('language') || this.getConfig().get('language')); },

        load: function () {
            if (!this.model.id) { return; }

            if (!this.lines) { this.$el.find('.order-lines-body').html(State.loading(this.translate('loading', 'labels', 'CarWash'))); }

            Espo.Ajax.getRequest('OrderAddon', {
                maxSize: 50,
                orderBy: 'sortOrder',
                order: 'asc',
                where: [{type: 'equals', attribute: 'washOrderId', value: this.model.id}],
            }).then(function (data) {
                this.lines = data.list || [];
                this.renderLines();
            }.bind(this)).catch(function (xhr) {
                if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
                this.$el.find('.order-lines-body').html(State.error({
                    auth: State.isAuthError(xhr), title: this.translate('loadError', 'labels', 'CarWash'),
                    retryLabel: this.translate('retry', 'labels', 'CarWash'), loginLabel: this.translate('relogin', 'labels', 'CarWash'),
                }));
                this.$el.find('.action-retry').on('click', this.load.bind(this));
                this.$el.find('.action-relogin').on('click', function () { window.location.reload(); });
            }.bind(this));
        },

        renderLines: function () {
            var lines = this.lines || [];

            if (!lines.length) {
                this.$el.find('.order-lines-body')
                    .html(State.empty({icon: 'fas fa-receipt', title: this.translate('None', 'labels', 'Global')}));

                return;
            }

            var total = 0;

            var rows = lines.map(function (l) {
                total += parseFloat(l.price) || 0;

                return '<tr>' +
                    '<td>' + this.esc(l.name) +
                        (l.isBase ? ' <span class="cw-chip">' +
                            this.translate('isBase', 'fields', 'OrderAddon') + '</span>' : '') +
                    '</td>' +
                    '<td class="cw-right cw-w-price">' +
                        '<input type="number" step="0.01" min="0" class="form-control line-price cw-input-money" data-id="' + l.id + '"' +
                        ' value="' + (l.price != null ? l.price : '') + '"> €' +
                    '</td>' +
                '</tr>';
            }.bind(this)).join('');

            this.$el.find('.order-lines-body').html(
                '<table class="cw-table"><tbody>' + rows + '</tbody>' +
                '<tfoot><tr class="cw-total">' +
                    '<td><b>' + this.translate('finalPrice', 'fields', 'WashOrder') + '</b></td>' +
                    '<td class="cw-right"><b class="lines-total">' + this.esc(this.money(total)) + '</b></td>' +
                '</tr></tfoot></table>'
            );
        },

        savePrice: function ($input) {
            var id = $input.data('id');
            var price = parseFloat($input.val());

            if (!id || isNaN(price)) { return; }

            // Saving the line is enough: the server hook (OrderAddon/UpdateOrderPrice)
            // re-derives the order's finalPrice, VAT split and printable HTML from the
            // lines — the client never writes the total itself.
            Espo.Ajax.putRequest('OrderAddon/' + id, {price: price})
                .then(function () {
                    (this.lines || []).forEach(function (l) {
                        if (l.id === id) { l.price = price; }
                    });

                    this.renderLines();

                    // Pull the recalculated total back so the field at the top agrees.
                    this.model.fetch({silent: false});
                    Espo.Ui.success(this.translate('Saved', 'labels', 'Global'));
                }.bind(this));
        },

    });
});
