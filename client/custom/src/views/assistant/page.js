/** The full-page assistant (#Assistant) — the tablet-friendly twin of the dashlet. */
define(['view'], function (Dep) {

    return Dep.extend({

        templateContent: '<div class="page-header"><h3>{{translate "title" category="labels" scope="Assistant"}}</h3></div><div class="as-host cw-as-page"></div>',

        afterRender: function () {
            this.createView('panel', 'custom:views/assistant/panel', {selector: '.as-host', mode: 'page', fromPage: 'Assistant'}, function (view) {
                view.render();
            });
        },
    });
});
