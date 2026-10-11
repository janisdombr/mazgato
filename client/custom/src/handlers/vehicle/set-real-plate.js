/**
 * "Set the real plate" on a car that carries a temporary number (docs/DATA_RULES.md). Shown only while
 * Vehicle.plateTemporary is set; the dialog is shared with the order header.
 */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        isAvailable() {
            return !!this.view.model.get('plateTemporary');
        }

        run() {
            this.view.createView('dialog', 'custom:views/vehicle/modals/set-real-plate', {
                vehicleId: this.view.model.id,
                onDone: function () {
                    this.view.model.fetch().then(function () { this.view.reRender(); }.bind(this));
                }.bind(this),
            }, function (v) { v.render(); });
        }
    };
});
