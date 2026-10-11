/** "Usage report" dialog opened from the Supplies list — hosts the shared report body. */
define(['views/modal'], function (Dep) {

    return Dep.extend({

        backdrop: true,

        templateContent: '<div class="supply-usage-report"></div>',

        setup: function () {
            this.headerText = this.translate('usageReport', 'labels', 'Supply');

            this.buttonList = [
                {name: 'cancel', label: 'Close'},
            ];

            this.createView('report', 'custom:views/supply-usage/report', {
                selector: '.supply-usage-report',
            });
        },

    });
});
