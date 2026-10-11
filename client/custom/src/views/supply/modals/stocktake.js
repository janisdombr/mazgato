/**
 * Stocktake dialog: every supply with its system quantity and an input for the physical count. Only counted lines are
 * sent (Inventory/action/stocktake); the server writes the differences to the append-only journal with the user and the
 * required reason, and sets the stock to the count. A per-dialog `clientKey` makes a retry harmless.
 */
define(['views/modal'], function (Dep) {

    return Dep.extend({

        backdrop: true,
        className: 'dialog dialog-record',

        templateContent: `
            <div class="supply-stocktake">
                <div class="form-group">
                    <label class="control-label">{{translate 'stocktakeReason' category='labels' scope='Supply'}}</label>
                    <input type="text" class="form-control field-reason" maxlength="250"
                        placeholder="{{translate 'reasonPlaceholder' category='labels' scope='Supply'}}">
                </div>
                <p class="text-muted">{{translate 'stocktakeHint' category='labels' scope='Supply'}}</p>
                <table class="table table-condensed">
                    <thead><tr>
                        <th>{{translate 'Supply' category='scopeNames'}}</th>
                        <th style="width:60px;">{{translate 'unitCol' category='labels' scope='Supply'}}</th>
                        <th style="width:110px;" class="text-right">{{translate 'systemQty' category='labels' scope='Supply'}}</th>
                        <th style="width:140px;">{{translate 'countedQty' category='labels' scope='Supply'}}</th>
                        <th style="width:100px;" class="text-right">{{translate 'diffCol' category='labels' scope='Supply'}}</th>
                    </tr></thead>
                    <tbody class="stocktake-lines"></tbody>
                </table>
                <div class="text-danger stocktake-error" style="display:none;"></div>
            </div>
        `,

        events: {
            'input .line-counted': function (e) { this.showDiff($(e.currentTarget).closest('tr')); },
        },

        setup: function () {
            this.headerText = this.translate('stocktakeHeader', 'labels', 'Supply');
            this.clientKey = 'st-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);

            this.buttonList = [
                {name: 'post', label: this.translate('postStocktake', 'labels', 'Supply'), style: 'primary', onClick: this.post.bind(this)},
                {name: 'cancel', label: 'Close'},
            ];
        },

        afterRender: function () {
            Espo.Ajax.postRequest('Inventory/action/sheet', {}).then(function (res) {
                var esc = function (v) { return $('<div>').text(v == null ? '' : String(v)).html(); };

                this.$el.find('.stocktake-lines').html(((res && res.list) || []).map(function (s) {
                    return '<tr data-id="' + esc(s.id) + '" data-system="' + esc(s.stockQuantity) + '"><td>' + esc(s.name) + '</td><td>' + esc(s.unit) + '</td>' +
                        '<td class="text-right">' + esc(s.stockQuantity) + '</td>' +
                        '<td><input type="number" class="form-control line-counted" min="0" step="any"></td>' +
                        '<td class="text-right line-diff"></td></tr>';
                }).join(''));
            }.bind(this));
        },

        showDiff: function ($tr) {
            var v = $tr.find('.line-counted').val();

            if (v === '') { $tr.find('.line-diff').text(''); return; }

            var d = Math.round((parseFloat(v) - parseFloat($tr.data('system'))) * 10000) / 10000;

            $tr.find('.line-diff').text((d > 0 ? '+' : '') + d);
        },

        showError: function (text) {
            this.$el.find('.stocktake-error').text(text).show();
        },

        post: function () {
            var reason = (this.$el.find('.field-reason').val() || '').trim();
            var lines = [];

            this.$el.find('.stocktake-lines tr').each(function (i, tr) {
                var v = $(tr).find('.line-counted').val();

                if (v !== '' && !isNaN(parseFloat(v))) { lines.push({supplyId: $(tr).data('id'), counted: parseFloat(v)}); }
            });

            if (!reason) { this.showError(this.translate('reasonRequired', 'labels', 'Supply')); return; }
            if (!lines.length) { this.showError(this.translate('nothingCounted', 'labels', 'Supply')); return; }

            this.disableButton('post');

            Espo.Ajax.postRequest('Inventory/action/stocktake', {clientKey: this.clientKey, reason: reason, lines: lines}).then(function (res) {
                Espo.Ui.success(this.translate('stocktakeDone', 'labels', 'Supply') + ': ' + ((res && res.varianceCount) || 0) + ' ' + this.translate('differencesWord', 'labels', 'Supply'));

                if (this.options.onDone) { this.options.onDone(); }

                this.close();
            }.bind(this)).catch(function () {
                this.enableButton('post');
                this.showError(this.translate('failed', 'labels', 'Supply'));
            }.bind(this));
        },
    });
});
