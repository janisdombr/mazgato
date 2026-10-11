/** "Receipt" button on the Supplies list - opens the goods-receipt dialog (create + post in one step). */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        run() {
            this.view.createView('supplyReceiptDialog', 'custom:views/supply/modals/receipt', {
                onDone: function () { if (this.view.collection) { this.view.collection.fetch(); } }.bind(this),
            }, function (view) {
                view.render();
            });
        }
    };
});
