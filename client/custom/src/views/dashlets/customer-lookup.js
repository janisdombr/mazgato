define(['custom:views/dashlets/base'], function (Dep) {

    return Dep.extend({

        name: 'CustomerLookup',

        templateContent: `
            <div class="cw-pad">
                <div class="cw-flex cw-bar">
                    <input type="text" class="form-control field-plate cw-input-plate"
                        placeholder="{{translate 'platePlaceholder' category='labels' scope='CustomerLookup'}}"
                        autocomplete="off"
                        >
                    <button class="cw-btn cw-btn--primary action-lookup">
                        {{translate 'lookup' category='labels' scope='CustomerLookup'}}
                    </button>
                </div>
                <div class="lookup-result"></div>
            </div>
        `,

        events: {
            'click .action-lookup': function () { this.lookup(); },
            'keydown .field-plate': function (e) {
                if (e.key === 'Enter') { e.preventDefault(); this.lookup(); }
            },
            'click .order-row': function (e) {
                var id = $(e.currentTarget).data('id');
                if (id) { this.getRouter().navigate('#WashOrder/view/' + id, {trigger: true}); }
            },
            'click .customer-link': function (e) {
                var id = $(e.currentTarget).data('id');
                if (id) { this.getRouter().navigate('#Customer/view/' + id, {trigger: true}); }
            },
        },

        afterRender: function () {
            this.$el.find('.field-plate').focus();
        },

        esc: function (v) { return this.cwState.esc(v); },

        // Same date notation as the rest of the CRM (D19), not an ISO slice.
        fmtDate: function (v) {
            if (!v) { return ''; }
            try { return this.getDateTime().toDisplayDate(v); } catch (e) { return String(v).slice(0, 10); }
        },

        t: function (name) { return this.translate(name, 'labels', 'CustomerLookup'); },

        lookup: function () {
            var plate = (this.$el.find('.field-plate').val() || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (!plate) { return; }

            var $btn = this.$el.find('.action-lookup').addClass('disabled');

            Espo.Ajax.postRequest('CarWash/action/customerInfo', {plate: plate})
                .then(function (res) {
                    $btn.removeClass('disabled');
                    this.render(res || {});
                }.bind(this))
                .catch(function (xhr) {
                    $btn.removeClass('disabled');
                    this.cwShowError(this.$el.find('.lookup-result'), xhr, this.lookup.bind(this));
                }.bind(this));
        },

        render: function (res) {
            var t = this.t.bind(this);
            var $r = this.$el.find('.lookup-result');

            if (!res.found || !res.customer) {
                $r.html(this.cwState.empty({icon: 'fas fa-magnifying-glass', title: t('notFound')}));
                return;
            }

            var c = res.customer;
            var st = res.stats || {};

            var card =
                '<div class="cw-qi-panel cw-box">' +
                    '<div class="cw-h"><a href="javascript:" class="customer-link" data-id="' + c.id + '"><b>' + this.esc(c.name) + '</b></a>' +
                        (c.isCorporate ? ' <span class="label label-info">' + t('corporate') + '</span>' : '') + '</div>' +
                    (c.phone ? '<div class="text-muted">' + this.esc(c.phone) + '</div>' : '') +
                    (c.email ? '<div class="text-muted">' + this.esc(c.email) + '</div>' : '') +
                    (c.isCorporate && c.regNumber ? '<div class="text-muted">Reg: ' + this.esc(c.regNumber) + (c.pvnNumber ? ' / ' + this.esc(c.pvnNumber) : '') + '</div>' : '') +
                    '<div class="cw-flex cw-flex--tight">' +
                        '<span class="label label-default">' + t('history') + ': ' + (st.totalOrders || 0) + '</span> ' +
                        '<span class="label label-success">' + t('spent') + ': ' + this.money(st.totalSpent || 0) + '</span>' +
                    '</div>' +
                '</div>';

            var rows = (res.orders || []).map(function (o) {
                return '<tr class="order-row cw-clickable" data-id="' + o.id + '">' +
                    '<td>' + this.fmtDate(o.orderDateTime) + '</td>' +
                    '<td>' + this.esc(o.vehicleName) + '</td>' +
                    '<td>' + this.esc(o.serviceName) + '</td>' +
                    '<td>' + this.esc(o.status) + '</td>' +
                    '<td class="cw-right">' + this.money(o.finalPrice) + '</td>' +
                '</tr>';
            }.bind(this)).join('');

            var table = rows
                ? '<div class="cw-scroll"><table class="cw-table"><tbody>' + rows + '</tbody></table></div>'
                : '<div class="text-muted">' + t('noHistory') + '</div>';

            $r.html(card + table);
        },

    });
});
