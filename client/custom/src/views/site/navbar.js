/**
 * The stock navbar plus one job: keep the "back" strip (custom:lib/backnav) in step with the address, on every route, so no
 * page is a dead end (read-only journals and pages without a menu item included). Registered in clientDefs/App.json.
 */
define('custom:views/site/navbar', ['views/site/navbar', 'custom:lib/backnav'], function (Dep, BackNav) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            var paint = function () { window.setTimeout(function () { BackNav.paint(this); }.bind(this), 0); }.bind(this);

            this.cwPaintBack = paint;
            $(window).on('hashchange.cwBack', paint);
            this.listenTo(this.getRouter(), 'routed', paint);
            this.once('remove', function () { $(window).off('hashchange.cwBack', paint); });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);
            this.cwPaintBack();
        },
    });
});
