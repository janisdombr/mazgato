define(['custom:views/dashlets/base'], function (Dep) {

    return Dep.extend({

        name: 'SupplyUsage',

        autoFit: true,

        // The report body itself lives in custom:views/supply-usage/report so the dashlet
        // and the "Usage report" dialog in the Supplies section stay identical.
        templateContent: `
            <div class="cw-pad">
                <div class="supply-usage-report" data-cw-fit></div>
            </div>
        `,

        afterRender: function () {
            this.createView('report', 'custom:views/supply-usage/report', {
                selector: '.supply-usage-report',
                usePeriod: true,
            }, function (view) {
                view.render();
            });
        },

    });
});
