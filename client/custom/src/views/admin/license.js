/**
 * Administration → Car wash → Licence: the installation id (send it to the vendor), the installed
 * signed licence (customer, modules, expiry, grace), upload / remove, and the state of each module.
 * Offline: the browser only talks to this CRM (License/action/*); nothing phones home.
 */
define(['view', 'custom:lib/state'], function (View, State) {

    var esc = State.esc;

    return View.extend({

        templateContent: '<div class="ap-body cw-pad"></div>',

        events: {
            'change .lic-file': function (e) { this.upload(e.currentTarget.files[0]); },
            'click .lic-remove': function () {
                Espo.Ajax.postRequest('License/action/remove', {}).then(function (d) { this.data = d; this.paint(); }.bind(this));
            },
        },

        L: function (key, section) { return this.translate(key, section || 'labels', 'License'); },

        afterRender: function () {
            var $b = this.$el.find('.ap-body');

            $b.html(State.loading(this.L('loading')));
            Espo.Ajax.postRequest('License/action/status', {}).then(function (d) {
                this.data = d;
                this.paint();
            }.bind(this)).catch(function () {
                $b.html(State.error({title: this.L('loadError'), retryLabel: this.L('retry')}));
                $b.find('.action-retry').on('click', this.afterRender.bind(this));
            }.bind(this));
        },

        upload: function (file) {
            if (!file) { return; }

            var reader = new FileReader();

            reader.onload = function () {
                Espo.Ajax.postRequest('License/action/upload', {content: String(reader.result)}).then(function (d) {
                    this.data = d;
                    Espo.Ui.success(this.L('uploaded'));
                    this.paint();
                }.bind(this)).catch(function (xhr) {
                    var code = xhr && xhr.getResponseHeader ? (xhr.getResponseHeader('X-Status-Reason') || '') : '';

                    Espo.Ui.error(this.translate(code, 'errors', 'License') !== code && code ? this.translate(code, 'errors', 'License') : this.L('uploadFailed'));
                    if (xhr && xhr.errorIsHandled !== undefined) { xhr.errorIsHandled = true; }
                }.bind(this));
            }.bind(this);
            reader.readAsText(file);
        },

        paint: function () {
            var d = this.data, h = '';

            h += '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('installation')) + '</h3>' +
                '<p><code class="lic-install-id">' + esc(d.installationId) + '</code></p>' +
                '<p class="cw-meta">' + esc(this.L('installationHint')) + '</p>' +
                '<p>' + esc(this.L('mode')) + ': <strong>' + esc(this.L(d.enforced ? 'modeEnforced' : 'modeOpen')) + '</strong></p>' +
                (d.hasKey ? '' : '<p class="cw-meta">' + esc(this.L('noKey')) + '</p>') + '</section>';

            h += '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('licence')) + '</h3>' +
                '<p>' + esc(this.L('status')) + ': <strong class="lic-status">' + esc(this.translate(d.status, 'statuses', 'License')) + '</strong></p>';

            if (d.customer) {
                h += '<p>' + esc(this.L('customer')) + ': ' + esc(d.customer) + '<br>' + esc(this.L('expires')) + ': ' + esc(d.expires || '') +
                    (d.graceUntil ? '<br>' + esc(this.L('graceUntil')) + ': ' + esc(d.graceUntil) : '') + '</p>';
            }

            h += '<p><input type="file" class="lic-file" accept=".json,application/json"> ' +
                (d.status !== 'missing' ? '<button type="button" class="cw-btn lic-remove">' + esc(this.L('remove')) + '</button>' : '') + '</p></section>';

            h += '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('modules')) + '</h3><table class="table"><thead><tr><th>' + esc(this.L('module')) +
                '</th><th>' + esc(this.L('licensed')) + '</th><th>' + esc(this.L('enabled')) + '</th></tr></thead><tbody>' +
                Object.keys(d.moduleState).map(function (m) {
                    var s = d.moduleState[m];

                    return '<tr><td>' + esc(this.translate(m, 'modules', 'License')) + '</td><td>' + esc(s.licensed ? '✓' : '—') + '</td><td>' + esc(s.enabled ? '✓' : '—') + '</td></tr>';
                }, this).join('') + '</tbody></table></section>';

            this.$el.find('.ap-body').html(h);
        },
    });
});
