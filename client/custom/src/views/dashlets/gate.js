/**
 * The "At the entrance" dashlet: the plate-recognition cards on a board of their own (the wash board
 * already embeds the same block - this is for dashboards that do not carry it). The block is
 * custom:views/anpr/entrance, which hides itself when the module is off.
 */
define(['custom:views/dashlets/base'], function (Dep) {

    return Dep.extend({

        name: 'Gate',

        autoFit: true,

        templateContent: '<div class="cw-pad"><div class="gate-dashlet-body" data-cw-fit></div></div>',

        afterRender: function () {
            this.createView('gate', 'custom:views/anpr/entrance', {selector: '.gate-dashlet-body'}, function (view) {
                view.render();
            });
        },

        // the dashlet's own refresh button
        actionRefresh: function () {
            var v = this.getView('gate');
            if (v && v.load) { v.load(true); }
        },
    });
});
