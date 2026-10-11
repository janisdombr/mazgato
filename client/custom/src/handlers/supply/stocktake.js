/** "Stocktake" button on the Supplies list - opens the stocktake dialog (physical count -> stock + journal). */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        run() {
            this.view.createView('supplyStocktakeDialog', 'custom:views/supply/modals/stocktake', {
                onDone: function () { if (this.view.collection) { this.view.collection.fetch(); } }.bind(this),
            }, function (view) {
                view.render();
            });
        }
    };
});
