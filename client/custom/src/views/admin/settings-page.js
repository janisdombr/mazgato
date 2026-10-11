/**
 * Shared Administration page for plain settings groups (Notifications, Online booking).
 * A subclass supplies `scope` (i18n scope), `groups` [{title, fields:[{key,type,options?}]}]
 * and optionally `extraHtml()` / `afterPaint()`. Values come from Booking/action/adminGet and
 * are saved with Booking/action/adminSave; a secret is never sent to the browser - its field
 * shows "set" and a blank input keeps the stored value.
 */
define(['view', 'custom:lib/state'], function (View, State) {

    var esc = State.esc;

    return View.extend({

        templateContent: '<div class="ap-body cw-pad"></div>',

        events: {
            'click .ap-save': function () { this.save(); },
            'click .ap-clear': function (e) { this.clearSecret($(e.currentTarget).data('key')); },
        },

        groups: [],

        // controller action prefix; a subclass whose settings live elsewhere overrides it
        api: 'Booking/action/',

        L: function (key, section) { return this.translate(key, section || 'labels', this.scope); },

        afterRender: function () { this.load(); },

        load: function () {
            var $b = this.$el.find('.ap-body');

            if (!this.data) { $b.html(State.loading(this.L('loading'))); }

            Espo.Ajax.postRequest(this.api + 'adminGet', {}).then(function (d) {
                this.data = d;
                this.paint();
            }.bind(this)).catch(function () {
                $b.html(State.error({title: this.L('loadError'), retryLabel: this.L('retry')}));
                $b.find('.action-retry').on('click', this.load.bind(this));
            }.bind(this));
        },

        /** An option's label from the page's i18n `options.<field>.<value>`; the raw value when there is none. */
        optionLabel: function (key, o) {
            var t = this.getLanguage().translateOption(o, key, this.scope);

            return t == null || t === '' ? o : t;
        },

        fieldHtml: function (f) {
            var d = this.data, id = 'ap-' + f.key, v = d.values[f.key];
            var label = this.L(f.key, 'fields');
            var hint = this.translate(f.key, 'hints', this.scope);
            hint = hint && hint !== f.key ? '<div class="cw-meta">' + esc(hint) + '</div>' : '';
            var input;

            if (f.type === 'bool') {
                return '<div class="cw-field cw-field--check"><input type="checkbox" id="' + id + '" data-key="' + f.key + '" data-type="bool"' + (v ? ' checked' : '') + '>' +
                    '<label for="' + id + '">' + esc(label) + '</label></div>' + hint;
            }
            if (f.type === 'select') {
                input = '<select class="form-control" id="' + id + '" data-key="' + f.key + '">' + f.options.map(function (o) {
                    return '<option value="' + esc(o) + '"' + ((v || f.options[0]) === o ? ' selected' : '') + '>' + esc(this.optionLabel(f.key, o)) + '</option>';
                }, this).join('') + '</select>';
            } else if (f.type === 'area') {
                input = '<textarea class="form-control" rows="3" id="' + id + '" data-key="' + f.key + '">' + esc(v || '') + '</textarea>';
            } else if (f.type === 'secret') {
                var set = d.secrets[f.key];
                input = '<input type="password" class="form-control" autocomplete="new-password" id="' + id + '" data-key="' + f.key + '" data-secret="1" placeholder="' + esc(set ? this.L('secretSet') : '') + '">' +
                    (set ? ' <button type="button" class="btn btn-link ap-clear" data-key="' + f.key + '">' + esc(this.L('clear')) + '</button>' : '');
            } else {
                input = '<input type="' + (f.type === 'int' ? 'number' : 'text') + '" class="form-control" id="' + id + '" data-key="' + f.key + '" data-type="' + (f.type || 'text') + '" value="' + esc(v == null ? '' : v) + '"' + (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '') + '>';
            }

            return '<div class="cw-field"><label for="' + id + '">' + esc(label) + '</label>' + input + hint + '</div>';
        },

        paint: function () {
            var h = '';

            if (this.extraTop) { h += this.extraTop(); }

            this.groups.forEach(function (g) {
                h += '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L(g.title, 'groups')) + '</h3><div class="cw-fields">' +
                    g.fields.map(this.fieldHtml.bind(this)).join('') + '</div></section>';
            }, this);

            h += '<div class="cw-actions"><button type="button" class="cw-btn cw-btn--primary ap-save">' + esc(this.L('save')) + '</button></div>';

            if (this.extraBottom) { h += this.extraBottom(); }

            this.$el.find('.ap-body').html(h);

            if (this.afterPaint) { this.afterPaint(); }
        },

        collect: function () {
            var values = {};

            this.$el.find('[data-key]').each(function () {
                var $i = $(this), k = $i.data('key');

                if ($i.data('type') === 'bool') { values[k] = $i.is(':checked'); }
                else if ($i.is('button')) { return; }
                else { values[k] = $i.val(); }
            });

            return values;
        },

        save: function (clear) {
            Espo.Ajax.postRequest(this.api + 'adminSave', {values: this.collect(), clear: clear || []}).then(function () {
                Espo.Ui.success(this.L('saved'));
                this.load();
            }.bind(this)).catch(function () {});
        },

        clearSecret: function (key) { this.save([key]); },
    });
});
