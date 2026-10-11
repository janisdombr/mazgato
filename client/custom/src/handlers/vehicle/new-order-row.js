/** Row-action handler: hand the plate to Quick Intake on the home dashboard. */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        actionNewOrder(data) {
            var plate = data.plate;

            if (!plate) { return; }

            try { window.sessionStorage.setItem('cwIntakePlate', plate); } catch (e) {}

            this.view.getRouter().navigate('#', {trigger: true});
        }
    };
});
