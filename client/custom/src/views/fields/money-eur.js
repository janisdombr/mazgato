/**
 * Money, one way everywhere (D15): "1 234,50 €", tabular digits, right-aligned in lists.
 * Stock currency view in edit mode (so entering a price is unchanged); only the READ
 * rendering is replaced, through lib/format.
 */
define(['views/fields/currency', 'custom:lib/format'], function (Dep, Fmt) {

    var TPL = '{{#if isNotEmpty}}<span class="cw-num cw-money">{{formatted}}</span>{{/if}}';

    return Dep.extend({

        listTemplateContent: TPL,
        detailTemplateContent: TPL,

        data: function () {
            var data = Dep.prototype.data.call(this);
            var lang = this.getPreferences().get('language') || this.getConfig().get('language');
            var v = this.model.get(this.name);

            data.formatted = Fmt.fmtMoney(v, lang);
            data.isNotEmpty = v !== null && v !== undefined && v !== '';

            return data;
        },

    });
});
