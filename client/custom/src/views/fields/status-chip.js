/**
 * One chip for both axes (D13/D14): WashOrder.status (work) and WashOrder.paymentStatus
 * (money) render as the same component — icon + word on a tinted surface — instead of
 * coloured text for one and a bootstrap label for the other. Edit mode is the stock enum.
 */
define(['views/fields/enum'], function (Dep) {

    var CHIPS = {
        status: {
            'Reserved': ['reserved', 'fa-calendar-check'],
            'New': ['wait', 'fa-hourglass-half'],
            'In Progress': ['progress', 'fa-droplet'],
            'Completed': ['done', 'fa-check'],
            'Cancelled': ['cancel', 'fa-ban'],
        },
        paymentStatus: {
            'Unpaid': ['unpaid', 'fa-euro-sign'],
            'Invoiced': ['invoiced', 'fa-file-invoice'],
            'Paid': ['paid', 'fa-check'],
        },
    };

    var TPL = '{{#if isNotEmpty}}<span class="cw-chip cw-chip--{{chipClass}}">' +
        '<i class="fas {{chipIcon}}" aria-hidden="true"></i>{{valueTranslated}}</span>{{/if}}';

    return Dep.extend({

        listTemplateContent: TPL,
        detailTemplateContent: TPL,

        data: function () {
            var data = Dep.prototype.data.call(this);
            var map = CHIPS[this.name] || {};
            var chip = map[this.model.get(this.name)] || ['cancel', 'fa-circle'];

            data.chipClass = chip[0];
            data.chipIcon = chip[1];

            return data;
        },

    });
});
