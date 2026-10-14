/**
 * Customer -> Loyalty -> "Adjust points": add or take away points with a REQUIRED reason (Loyalty/action/adjustPoints; administrator or a
 * role that may edit every customer). The server refuses a balance below zero; the ledger keeps who did it and why.
 */
define(['views/modal', 'custom:lib/state'], function (Dep, State) {

    return Dep.extend({

        backdrop: true,

        templateContent:
            '<div class="cw-adjust">' +
                '<div class="form-group"><label class="control-label">{{translate \'pointsAdjustPoints\' category=\'labels\' scope=\'Customer\'}}</label>' +
                '<input type="number" step="1" class="form-control field-points"></div>' +
                '<div class="form-group"><label class="control-label">{{translate \'pointsAdjustReason\' category=\'labels\' scope=\'Customer\'}}</label>' +
                '<input type="text" maxlength="250" class="form-control field-reason"></div>' +
                '<div class="text-danger cw-adjust-error" hidden></div>' +
            '</div>',

        setup: function () {
            this.headerText = this.translate('pointsAdjust', 'labels', 'Customer');
            this.buttonList = [
                {name: 'apply', label: this.translate('confirm', 'labels', 'Customer'), style: 'primary', onClick: this.apply.bind(this)},
                {name: 'cancel', label: this.translate('cancel', 'labels', 'Customer')},
            ];
        },

        apply: function () {
            var pts = parseInt(this.$el.find('.field-points').val(), 10);
            var reason = (this.$el.find('.field-reason').val() || '').trim();
            var $err = this.$el.find('.cw-adjust-error');

            if (!pts || reason.length < 3) {
                $err.text(this.translate('pointsAdjustReason', 'labels', 'Customer')).prop('hidden', false);

                return;
            }

            Espo.Ajax.postRequest('Loyalty/action/adjustPoints', {customerId: this.options.customerId, points: pts, reason: reason})
                .then(function (r) {
                    Espo.Ui.success(this.translate('pointsAdjusted', 'labels', 'Customer'));
                    this.trigger('done', r);
                    this.close();
                }.bind(this))
                .catch(function (xhr) {
                    var reasonCode = xhr && xhr.getResponseHeader ? xhr.getResponseHeader('X-Status-Reason') : '';

                    if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
                    $err.text(reasonCode || 'error').prop('hidden', false);
                }.bind(this));
        },
    });
});
