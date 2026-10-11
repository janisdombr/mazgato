/**
 * Row actions for a vehicle: "Новый заказ" right in the list, no need to open the car.
 * The item carries data-handler, so Espo's handleAction loads the handler module and calls
 * actionNewOrder(data) on it — no custom list view needed.
 */
define(['views/record/row-actions/default'], function (Dep) {

    return Dep.extend({

        getActionList: function () {
            var list = Dep.prototype.getActionList.call(this);

            list.unshift({
                action: 'newOrder',
                label: this.translate('newOrder', 'labels', 'Customer'),
                data: {
                    handler: 'custom:handlers/vehicle/new-order-row',
                    plate: this.model.get('name'),
                },
            });

            return list;
        },

    });
});
