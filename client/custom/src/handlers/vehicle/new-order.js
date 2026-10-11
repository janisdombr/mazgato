/**
 * "Новый заказ" on a vehicle: jump to the Quick Intake panel on the home dashboard with
 * this plate already looked up. The plate travels in sessionStorage — the dashboard is a
 * dashlet grid with no URL parameters of its own.
 */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        run() {
            var plate = this.view.model.get('name');

            if (!plate) { return; }

            try { window.sessionStorage.setItem('cwIntakePlate', plate); } catch (e) {}

            this.view.getRouter().navigate('#', {trigger: true});
        }
    };
});
