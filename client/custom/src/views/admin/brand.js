/**
 * Administration → Brand: the client's identity (logo, name, colour, document header and
 * footer, email signature, opt-out site). Stock settings editor over our own layout, so
 * saving, validation and permissions are Espo's; nothing here is product-name-specific.
 */
define(['views/settings/record/edit'], function (Dep) {

    return Dep.extend({

        layoutName: 'brand',

        saveAndContinueEditingAction: false,

        // The brand colour and name apply on the next page load (they are read at boot).
        setup: function () {
            Dep.prototype.setup.call(this);

            this.on('save', function () {
                Espo.Ui.notify(this.translate('brandReload', 'messages', 'Admin'), 'success', 4000);
            }, this);
        },
    });
});
