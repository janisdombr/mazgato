/**
 * Reorder services in one dialog: two lists (base washes and add-ons), ▲/▼ per row.
 * Saving renumbers each list 10, 20, 30 … and PUTs only the records whose number changed —
 * far fewer clicks than opening every service and editing "Sort order" by hand.
 *
 * The add-on order is what the quick-intake form shows, so this is the screen that
 * controls it.
 */
define(['views/modal'], function (Dep) {

    return Dep.extend({

        backdrop: true,

        templateContent: `
            <div class="service-sort">
                <div class="sort-body"><div class="text-muted">…</div></div>
            </div>
        `,

        events: {
            'click .action-up': function (e) { this.move($(e.currentTarget).data('id'), -1); },
            'click .action-down': function (e) { this.move($(e.currentTarget).data('id'), 1); },
        },

        setup: function () {
            this.headerText = this.translate('sortServices', 'labels', 'WashService');

            this.buttonList = [
                {name: 'save', label: 'Save', style: 'primary', onClick: this.save.bind(this)},
                {name: 'cancel', label: 'Cancel'},
            ];

            this.wait(
                Espo.Ajax.getRequest('WashService', {maxSize: 200, orderBy: 'sortOrder', order: 'asc'})
                    .then(function (data) {
                        var list = (data.list || []).map(function (s) {
                            return {
                                id: s.id,
                                name: s.name,
                                category: s.category,
                                sortOrder: s.sortOrder == null ? 9999 : s.sortOrder,
                            };
                        });

                        // Equal sort numbers (e.g. everything still at the default) would
                        // otherwise come back in an arbitrary order — fall back to the name.
                        list.sort(function (a, b) {
                            return a.sortOrder !== b.sortOrder
                                ? a.sortOrder - b.sortOrder
                                : (a.name || '').localeCompare(b.name || '');
                        });

                        this.base = list.filter(function (s) { return s.category !== 'Add-on'; });
                        this.addons = list.filter(function (s) { return s.category === 'Add-on'; });
                    }.bind(this))
            );
        },

        afterRender: function () {
            this.renderLists();
        },

        esc: function (v) { return $('<div>').text(v == null ? '' : v).html(); },

        listOf: function (id) {
            return this.addons.some(function (s) { return s.id === id; }) ? this.addons : this.base;
        },

        move: function (id, step) {
            var list = this.listOf(id);
            var i = list.findIndex(function (s) { return s.id === id; });
            var j = i + step;

            if (i < 0 || j < 0 || j >= list.length) { return; }

            var tmp = list[i];
            list[i] = list[j];
            list[j] = tmp;

            this.renderLists();
        },

        renderBlock: function (title, list) {
            var rows = list.map(function (s, i) {
                return '<tr>' +
                    '<td style="width:28px; text-align:right; color:#999;">' + ((i + 1) * 10) + '</td>' +
                    '<td>' + this.esc(s.name) + '</td>' +
                    '<td style="text-align:right; white-space:nowrap;">' +
                        '<button class="btn btn-default btn-sm action-up" data-id="' + s.id + '"' +
                            (i === 0 ? ' disabled' : '') + '>▲</button> ' +
                        '<button class="btn btn-default btn-sm action-down" data-id="' + s.id + '"' +
                            (i === list.length - 1 ? ' disabled' : '') + '>▼</button>' +
                    '</td>' +
                '</tr>';
            }.bind(this)).join('');

            return '<h5 style="margin:10px 0 4px;">' + this.esc(title) + '</h5>' +
                '<table class="table table-sm" style="font-size:13px; margin-bottom:0;"><tbody>' + rows + '</tbody></table>';
        },

        renderLists: function () {
            var addonLabel = this.getLanguage().translateOption('Add-on', 'category', 'WashService');
            var baseLabel = this.getLanguage().translateOption('Base', 'category', 'WashService');

            this.$el.find('.sort-body').html(
                this.renderBlock(addonLabel, this.addons) +
                this.renderBlock(baseLabel, this.base)
            );
        },

        save: function () {
            // Renumber 10, 20, 30 … so a later manual insert has room in between.
            var changed = [];

            [this.addons, this.base].forEach(function (list) {
                list.forEach(function (s, i) {
                    var value = (i + 1) * 10;

                    if (s.sortOrder !== value) {
                        changed.push({id: s.id, sortOrder: value});
                    }
                });
            });

            if (!changed.length) { this.close(); return; }

            Espo.Ui.notifyWait();

            Promise.all(changed.map(function (r) {
                return Espo.Ajax.putRequest('WashService/' + r.id, {sortOrder: r.sortOrder});
            })).then(function () {
                Espo.Ui.success(this.translate('Saved', 'labels', 'Global'));
                this.trigger('saved');
                this.close();
            }.bind(this)).catch(function () {
                Espo.Ui.error(this.translate('Error', 'labels', 'Global'));
            }.bind(this));
        },

    });
});
