/**
 * "Итоги" on a list screen: count and sum for the filter currently applied. The where
 * clause is taken from the collection the list itself was fetched with, so the numbers
 * always match what is on screen (export uses the same filter).
 */
define([], function () {

    return class {

        constructor(view) {
            this.view = view;
        }

        run() {
            var view = this.view;
            var collection = view.collection;

            if (!collection) { return; }

            Espo.Ajax.postRequest('CarWash/action/listTotals', {
                entityType: collection.entityType || collection.name,
                where: collection.where || [],
            }).then(function (res) {
                var t = function (name) { return view.translate(name, 'labels', 'Global'); };

                var body =
                    '<table class="table table-sm" style="font-size:14px; margin:0;"><tbody>' +
                        '<tr><td>' + view.translate('Total', 'labels', 'Global') + '</td>' +
                            '<td style="text-align:right;"><b>' + res.count + '</b></td></tr>' +
                        '<tr><td>' + view.translate(res.sumField, 'fields', res.entityType) + '</td>' +
                            '<td style="text-align:right;"><b>' + res.sum + '</b></td></tr>' +
                        '<tr><td>' + view.translate('Average', 'labels', 'Global') + '</td>' +
                            '<td style="text-align:right;">' + res.average + '</td></tr>' +
                    '</tbody></table>';

                var dialog = Espo.Ui.dialog({
                    header: view.translate('listTotals', 'labels', 'Global'),
                    body: body,
                    buttonList: [{
                        name: 'close',
                        text: t('Close'),
                        onClick: function (d) { d.close(); },
                    }],
                });

                dialog.show();
            }).catch(function (e) {
                Espo.Ui.error(String((e && e.message) || e));
            });
        }
    };
});
