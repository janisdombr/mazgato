/**
 * Administration: the stock controller with one change - the start page (#Admin) is the car-wash task home
 * (custom:views/admin/home). Every other page (#Admin/<page>, including the stock ones) is untouched; the stock index is
 * rendered inside the home page under "Show all standard settings".
 */
define('custom:controllers/admin', ['controllers/admin'], function (Dep) {

    return Dep.extend({

        actionIndex: function (options) {
            var isReturn = options && options.isReturn;

            if (this.getRouter().backProcessed) { isReturn = true; }
            if (!isReturn && this.getStoredMainView('index')) { this.clearStoredMainView('index'); }

            this.main('custom:views/admin/home', null, function (view) {
                view.render();
                this.listenTo(view, 'clear-cache', this.clearCache);
                this.listenTo(view, 'rebuild', this.rebuild);
            }.bind(this), {useStored: isReturn, key: 'index'});
        },
    });
});
