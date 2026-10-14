/** #Calendar - the order calendar for staff (Day / Week / Month), replacing the stock activities calendar of this install. */
define(['controller'], function (Dep) {

    return Dep.extend({

        defaultAction: 'index',

        // the page needs the right to read orders; the server (Booking/action/range) asks for read: all again
        checkAccess: function () { return this.getAcl().checkScope('WashOrder', 'read'); },

        actionIndex: function () {
            this.main('custom:views/calendar/page', {});
        },
    });
});
