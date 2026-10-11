/**
 * Dialog: type the real plate of a car that has a temporary number. The car is renamed (history stays); a plate that
 * already belongs to another car answers 409 `plate_exists` and the dialog offers to MERGE (orders move there).
 * Options: vehicleId, onDone().
 */
define(['views/modal'], function (Dep) {

    return Dep.extend({

        className: 'dialog dialog-record',

        templateContent:
            '<div class="cw-pad">' +
            '<p class="cw-meta set-plate-hint"></p>' +
            '<label class="cw-meta" for="set-plate-input"></label>' +
            '<input type="text" id="set-plate-input" class="form-control cw-input-plate set-plate-input" maxlength="14" autocomplete="off">' +
            '<div class="set-plate-msg cw-gap" role="alert"></div>' +
            '</div>',

        setup: function () {
            this.vehicleId = this.options.vehicleId;
            this.merge = false;
            this.headerText = this.translate('setRealPlate', 'labels', 'Vehicle');
            this.buttonList = [
                {name: 'save', label: 'Save', style: 'primary'},
                {name: 'cancel', label: 'Cancel'},
            ];
        },

        afterRender: function () {
            var t = function (k) { return this.translate(k, 'labels', 'Vehicle'); }.bind(this);

            this.$el.find('.set-plate-hint').text(t('setRealPlateHint'));
            this.$el.find('label[for="set-plate-input"]').text(t('realPlate'));
            this.$el.find('.set-plate-input').on('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); this.actionSave(); }
            }.bind(this)).trigger('focus');
        },

        actionSave: function () {
            var t = function (k) { return this.translate(k, 'labels', 'Vehicle'); }.bind(this);
            var plate = (this.$el.find('.set-plate-input').val() || '').trim();
            var $msg = this.$el.find('.set-plate-msg').empty();

            if (!plate) { $msg.text(t('plateRequired')); return; }

            this.disableButton('save');

            Espo.Ajax.postRequest('CarWash/action/setRealPlate', {vehicleId: this.vehicleId, plate: plate, merge: this.merge}).then(function (res) {
                Espo.Ui.success(t(res.merged ? 'carsMerged' : 'plateChanged'));
                this.close();

                if (this.options.onDone) { this.options.onDone(res); }
            }.bind(this)).catch(function (xhr) {
                if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }

                var body = {};

                try { body = JSON.parse(xhr.responseText || '{}'); } catch (e) { /* not JSON */ }

                this.enableButton('save');

                if (body.error === 'plate_exists' && body.canMerge) {
                    this.merge = true;
                    $msg.text(t('mergeAsk'));
                    this.$el.find('.btn[data-name="save"]').text(t('merge'));

                    return;
                }

                var reason = (xhr && xhr.getResponseHeader && xhr.getResponseHeader('X-Status-Reason')) || '';

                $msg.text(reason === 'plate_required' ? t('plateRequired') : (reason || t('plateRequired')));
            }.bind(this));
        },
    });
});
