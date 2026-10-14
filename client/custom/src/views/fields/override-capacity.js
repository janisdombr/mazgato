/**
 * "Book anyway (over capacity)" box of a reservation. Only a manager / administrator (scope CapacityOverride) ever sees it, and only
 * while editing a Reserved order; the server decides again (403 for anyone else, ReserveGuard) - this view only keeps the box out of
 * the way of the people who may not use it.
 */
define(['views/fields/bool'], function (Dep) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.cwAllowed = this.getUser().isAdmin() || this.getAcl().checkScope('CapacityOverride');
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (!this.cwAllowed || this.mode !== 'edit') { this.hide(); }
        },

        show: function () {
            if (!this.cwAllowed || this.mode !== 'edit') { return; }

            Dep.prototype.show.call(this);
        },
    });
});
