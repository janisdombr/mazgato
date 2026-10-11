/** Same "Новый заказ" row action inside a relationship panel (customer's vehicles). */
define(['views/record/row-actions/relationship'], function (Dep) {

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
