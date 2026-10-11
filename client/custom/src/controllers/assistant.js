define(['controller'], function (Dep) {

    return Dep.extend({

        defaultAction: 'index',

        checkAccess: function () { return true; },

        actionIndex: function () {
            this.main('custom:views/assistant/page', {});
        },
    });
});
