/**
 * "Новый заказ" on a customer: pick one of their cars (straight through when there is only
 * one), then open Quick Intake on the home dashboard with that plate.
 */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        run() {
            var view = this.view;

            Espo.Ajax.getRequest('Vehicle', {
                maxSize: 50,
                orderBy: 'name',
                where: [{type: 'equals', attribute: 'customerId', value: view.model.id}],
            }).then(function (data) {
                var list = data.list || [];

                if (!list.length) {
                    Espo.Ui.warning(view.translate('noVehicles', 'labels', 'Customer'));

                    return;
                }

                if (list.length === 1) {
                    this.go(list[0].name);

                    return;
                }

                var html = list.map(function (v) {
                    return '<button class="btn btn-default btn-block plate-pick" data-plate="' +
                        $('<div>').text(v.name).html() + '" style="margin-bottom:6px; letter-spacing:1px;">' +
                        $('<div>').text(v.name).html() + '</button>';
                }).join('');

                var dialog = Espo.Ui.dialog({
                    header: view.translate('newOrder', 'labels', 'Customer'),
                    body: html,
                    buttonList: [{name: 'cancel', text: view.translate('Cancel', 'labels', 'Global'), onClick: function (d) { d.close(); }}],
                });

                dialog.show();

                $(dialog.$el).on('click', '.plate-pick', function (e) {
                    dialog.close();
                    this.go($(e.currentTarget).data('plate'));
                }.bind(this));
            }.bind(this));
        }

        go(plate) {
            try { window.sessionStorage.setItem('cwIntakePlate', plate); } catch (e) {}

            this.view.getRouter().navigate('#', {trigger: true});
        }
    };
});
