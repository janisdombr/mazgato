/** "Set PIN" on an employee card — opens the PIN dialog. */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        run() {
            this.view.createView('setPin', 'custom:views/employee/modals/set-pin', {
                employeeId: this.view.model.id,
                name: this.view.model.get('name'),
            }, function (view) {
                view.render();
                this.view.listenToOnce(view, 'done', function () { this.view.model.fetch(); }.bind(this));
            }.bind(this));
        }
    };
});
