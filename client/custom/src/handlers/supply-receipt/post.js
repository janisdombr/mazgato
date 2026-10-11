/** "Post receipt" button on a Draft goods receipt: Inventory/action/postReceipt (idempotent, raises the stock once). */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        isDraft() {
            return this.view.model && this.view.model.get('status') === 'Draft';
        }

        run() {
            var model = this.view.model;

            Espo.Ajax.postRequest('Inventory/action/postReceipt', {id: model.id}).then(function (res) {
                Espo.Ui.success(this.view.translate(res && res.replayed ? 'alreadyPosted' : 'posted', 'labels', 'SupplyReceipt'));
                model.fetch();
                this.view.reRender ? this.view.reRender() : null;
            }.bind(this)).catch(function () {
                Espo.Ui.error(this.view.translate('failed', 'labels', 'Supply'));
            }.bind(this));
        }
    };
});
