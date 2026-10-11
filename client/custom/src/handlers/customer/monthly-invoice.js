/** "Счёт за месяц" on a customer — opens the consolidated invoice dialog. */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        run() {
            this.view.createView('monthlyInvoice', 'custom:views/customer/modals/monthly-invoice', {
                customerId: this.view.model.id,
            }, function (view) {
                view.render();
            });
        }
    };
});
