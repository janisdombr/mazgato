/**
 * "Счёт за месяц": one consolidated invoice for every completed order of this customer in
 * a period. The body is rendered server-side from the editable "Rēķins par mēnesi" Template
 * record, so the wording is maintained in the UI like the other documents; this view only
 * picks the period, previews it and prints (the browser saves it as PDF).
 */
define(['views/modal'], function (Dep) {

    return Dep.extend({

        backdrop: true,
        className: 'dialog dialog-record',

        templateContent: `
            <div class="monthly-invoice">
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:10px;">
                    <input type="date" class="form-control field-from" style="flex:1;">
                    <span>—</span>
                    <input type="date" class="form-control field-to" style="flex:1;">
                    <select class="form-control field-doc" style="flex:1;" title="{{translate 'document' category='labels' scope='Customer'}}">
                        <option value="invoice">{{translate 'documentInvoice' category='labels' scope='Customer'}}</option>
                        <option value="act">{{translate 'documentAct' category='labels' scope='Customer'}}</option>
                    </select>
                    <select class="form-control field-template" style="flex:1.4;"></select>
                    <button class="btn btn-default action-reload">
                        {{translate 'refresh' category='labels' scope='SupplyUsage'}}
                    </button>
                </div>
                <div class="invoice-body"><div class="text-muted">…</div></div>
            </div>
        `,

        events: {
            'click .action-reload': function () { this.load(); },
            // Switching the template re-renders the document with the new wording.
            'change .field-template': function () { this.load(); },
            // The act of work performed is the second document of the same period / same orders.
            'change .field-doc': function () {
                this.$el.find('.field-template').toggle(this.currentDoc() === 'invoice');
                this.renderInvoice();
            },
        },

        setup: function () {
            this.customerId = this.options.customerId;

            this.headerText = this.translate('monthlyInvoice', 'labels', 'Customer');

            this.buttonList = [
                {name: 'print', label: this.translate('Print', 'labels', 'Global'), style: 'primary',
                    onClick: this.print.bind(this)},
                {name: 'cancel', label: 'Close'},
            ];
        },

        afterRender: function () {
            // Default period: the previous whole month — that is what gets invoiced.
            var now = new Date();
            var first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            var last = new Date(now.getFullYear(), now.getMonth(), 0);

            this.$el.find('.field-from').val(this.toIso(first));
            this.$el.find('.field-to').val(this.toIso(last));

            this.load();
        },

        toIso: function (d) {
            var m = d.getMonth() + 1;
            var day = d.getDate();

            return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
        },

        load: function () {
            var from = this.$el.find('.field-from').val();
            var to = this.$el.find('.field-to').val();

            Espo.Ajax.postRequest('CarWash/action/monthlyInvoice', {
                customerId: this.customerId,
                dateFrom: from ? from + ' 00:00:00' : null,
                dateTo: to ? to + ' 23:59:59' : null,
                templateId: this.$el.find('.field-template').val() || null,
            }).then(function (res) {
                this.data = res || {};
                this.renderTemplateList();
                this.renderInvoice();
            }.bind(this));
        },

        // Which template the document is rendered with — editable in Administration →
        // Templates, so the wording is not baked into the code.
        renderTemplateList: function () {
            var $select = this.$el.find('.field-template');
            var list = (this.data || {}).templates || [];

            if (!list.length || $select.find('option').length === list.length) { return; }

            var current = $select.val();

            $select.html(list.map(function (t) {
                return '<option value="' + t.id + '"' + (t.id === current ? ' selected' : '') + '>' +
                    $('<div>').text(t.name).html() + '</option>';
            }).join(''));
        },

        // The document body comes from the editable "Rēķins par mēnesi" Template record —
        // the server renders it, the client only shows and prints it.
        currentDoc: function () {
            return this.$el.find('.field-doc').val() === 'act' ? 'act' : 'invoice';
        },

        buildHtml: function () {
            var d = this.data || {};

            if (!d.ordersCount) {
                return '<div class="text-muted">' + this.translate('noOrders', 'labels', 'TodayOrders') + '</div>';
            }

            return (this.currentDoc() === 'act' ? d.actHtml : d.html) || '';
        },

        renderInvoice: function () {
            this.$el.find('.invoice-body').html(this.buildHtml());
        },

        print: function () {
            var w = window.open('', '_blank');

            if (!w) { Espo.Ui.warning('Popup blocked'); return; }

            // Only page setup here — NO table/border rules. Any styling the print window
            // adds on its own would make the printed document differ from the preview
            // (a generic "td { border-bottom }" drew a line under every row). How the
            // document looks is the template's business, and only the template's.
            w.document.write(
                '<html><head><meta charset="utf-8"><title>' + (this.currentDoc() === 'act' ? 'Akts' : 'Rēķins') + '</title>' +
                '<style>@page{margin:16mm}' +
                'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;margin:0}' +
                'table{border-collapse:collapse}' +
                '</style></head><body>' + this.buildHtml() + '</body></html>'
            );

            w.document.close();
            w.focus();
            w.print();
        },

    });
});
