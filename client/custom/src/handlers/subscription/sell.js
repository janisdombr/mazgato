/**
 * "Sell subscription" button - on the Subscriptions list and on a customer's card. Opens the sell dialog;
 * everything that matters (revenue order, balance, fiscal status) is decided by Subscription/action/sell.
 */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        run() {
            var model = this.view.model;
            var customerId = model && model.entityType === 'Customer' ? model.id : null;
            var customerName = model && model.entityType === 'Customer' ? model.get('name') : null;

            this.view.createView('sellSubscription', 'custom:views/subscription/modals/sell', {
                customerId: customerId, customerName: customerName,
            }, function (v) {
                v.render();
                this.view.listenToOnce(v, 'sold', function () {
                    if (model && model.fetch) { model.fetch(); }
                    if (this.view.collection && this.view.collection.fetch) { this.view.collection.fetch(); }
                }.bind(this));
            }.bind(this));
        }
    };
});
