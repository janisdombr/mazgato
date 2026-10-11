/**
 * Set or replace a worker's tablet PIN. The PIN is typed here, sent once to
 * Kiosk/action/setPin and hashed on the server; it is never shown again, stored in the
 * browser or readable through the API (a forgotten PIN is replaced, not recovered).
 */
define(['views/modal', 'custom:lib/state'], function (Dep, State) {

    return Dep.extend({

        backdrop: true,
        className: 'dialog',

        templateContent:
            '<div class="cw-flex cw-flex--wide">' +
                '<div class="cw-grow">' +
                    '<label class="cw-h">{{translate "setPin" category="labels" scope="Employee"}} — <span class="sp-name"></span></label>' +
                    '<input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="new-password" ' +
                        'class="form-control sp-pin cw-input-pin" placeholder="••••">' +
                    '<div class="cw-meta sp-hint"></div>' +
                    '<div class="cw-neg sp-error" role="alert"></div>' +
                '</div>' +
            '</div>',

        events: {
            'keydown .sp-pin': function (e) { if (e.key === 'Enter') { this.save(); } },
        },

        setup: function () {
            this.headerText = this.translate('setPin', 'labels', 'Employee');
            this.buttonList = [
                {name: 'save', label: this.translate('Save', 'labels', 'Global'), style: 'primary', onClick: this.save.bind(this)},
                {name: 'cancel', label: this.translate('Cancel', 'labels', 'Global')},
            ];
        },

        afterRender: function () {
            this.$el.find('.sp-name').text(this.options.name || '');
            this.$el.find('.sp-hint').text(this.translate('pinHint', 'labels', 'Employee'));
            this.$el.find('.sp-pin').focus();
        },

        save: function () {
            var pin = String(this.$el.find('.sp-pin').val() || '');
            var $err = this.$el.find('.sp-error');

            $err.text('');

            if (!/^\d{4,6}$/.test(pin)) {
                $err.text(this.translate('pinInvalid', 'labels', 'Employee'));
                return;
            }

            Espo.Ajax.postRequest('Kiosk/action/setPin', {employeeId: this.options.employeeId, pin: pin})
                .then(function () {
                    Espo.Ui.success(this.translate('pinSaved', 'labels', 'Employee'));
                    this.trigger('done');
                    this.close();
                }.bind(this))
                .catch(function () {
                    $err.text(this.translate('pinInvalid', 'labels', 'Employee'));
                    Espo.Ui.notify(false);
                }.bind(this));
        },
    });
});
