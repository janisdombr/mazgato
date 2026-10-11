/** Assistant dashlet: the chat panel inside the dashboard. The body is custom:views/assistant/panel. */
define(['custom:views/dashlets/base'], function (Dep) {

    return Dep.extend({

        name: 'Assistant',

        autoFit: true,

        templateContent: '<div class="as-host" data-cw-fit></div>',

        afterRender: function () {
            this.createView('panel', 'custom:views/assistant/panel', {selector: '.as-host', mode: 'dashlet', fromPage: 'Home'}, function (view) {
                view.render();
            });
        },
    });
});
