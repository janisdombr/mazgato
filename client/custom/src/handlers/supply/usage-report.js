/**
 * "Usage report" button on the Supplies list — opens the supply-usage report dialog.
 * Espo instantiates the handler with the view and calls the configured actionFunction.
 */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        run() {
            this.view.createView('supplyUsageDialog', 'custom:views/supply/modals/usage', {}, function (view) {
                view.render();
            });
        }
    };
});
