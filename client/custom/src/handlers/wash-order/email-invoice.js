define(function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        emailInvoice() {
            const view = this.view;
            const model = view.model;

            view.confirm(view.translate('emailInvoiceConfirm', 'messages', 'WashOrder'))
                .then(() => {
                    Espo.Ui.notify(view.translate('sending', 'messages', 'WashOrder'));

                    Espo.Ajax.postRequest('CarWash/action/emailInvoice', {orderId: model.id})
                        .then(response => {
                            Espo.Ui.success(
                                view.translate('invoiceSent', 'messages', 'WashOrder') +
                                (response && response.to ? ' (' + response.to + ')' : '')
                            );
                        })
                        .catch(() => {
                            // The server error message (e.g. SMTP/email missing) is shown by the framework.
                            Espo.Ui.notify(false);
                        });
                });
        }
    };
});
