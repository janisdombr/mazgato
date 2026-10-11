/**
 * A name that is data (service / work), shown in the user's language through the
 * Translation table (D32). Editing is the stock varchar — the stored value stays what the
 * client typed, only the READ rendering is translated.
 */
define(['views/fields/varchar', 'custom:lib/translations'], function (Dep, Tr) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            if (!Tr.isLoaded() && this.mode !== 'edit') {
                Tr.load(this.getPreferences().get('language') || this.getConfig().get('language'))
                    .then(function () { if (this.isRendered()) { this.reRender(); } }.bind(this));
            }
        },

        getValueForDisplay: function () {
            var v = Dep.prototype.getValueForDisplay.call(this);

            return this.mode === 'edit' ? v : Tr.tr(v);
        },
    });
});
