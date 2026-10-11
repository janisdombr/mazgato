/** "Порядок" button on the services list — opens the drag-free reorder dialog. */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        run() {
            this.view.createView('serviceSortDialog', 'custom:views/wash-service/modals/sort', {}, function (view) {
                view.render();

                // The list must show the new order right after saving.
                view.once('saved', function () {
                    if (this.view.collection) { this.view.collection.fetch(); }
                }.bind(this));
            }.bind(this));
        }
    };
});
