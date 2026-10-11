/**
 * Administration → Car wash → Tablets. One page for the worker tablet:
 *   - status of the module flag, the tablet address (link + QR),
 *   - devices: "Issue device" creates an API user with the Kiosk role and shows its key ONCE
 *     (as text and as a QR with the setup link), revoke cuts a tablet off at once,
 *   - staff with their PIN status, set / unlock,
 *   - the numbers that govern the tablet (idle, session, PIN lock, rate limit, retention).
 * Everything comes from Kiosk/action/adminOverview; there is no state in the page but that.
 */
define(['view', 'custom:lib/state', 'custom:views/employee/modals/set-pin'], function (View, State) {

    var esc = State.esc;

    return View.extend({

        templateContent:
            '<div class="kn-body cw-pad"></div>',

        events: {
            'click .kn-issue': function () { this.issue(); },
            'click .kn-revoke': function (e) { this.revoke($(e.currentTarget).data('id')); },
            'click .kn-setpin': function (e) { this.setPin($(e.currentTarget).data('id'), $(e.currentTarget).data('name')); },
            'click .kn-unlock': function (e) { this.unlock($(e.currentTarget).data('id')); },
            'click .kn-copy': function (e) { this.copy($(e.currentTarget).data('text')); },
            'click .kn-save': function () { this.saveSettings(); },
        },

        L: function (key) { return this.translate(key, 'labels', 'Kiosk'); },

        afterRender: function () {
            this.load();
        },

        load: function () {
            var $body = this.$el.find('.kn-body');

            if (!this.data) { $body.html(State.loading(this.translate('loading', 'labels', 'CarWash'))); }

            Espo.Ajax.postRequest('Kiosk/action/adminOverview', {}).then(function (data) {
                this.data = data;
                this.paint();
            }.bind(this)).catch(function () {
                $body.html(State.error({title: this.translate('loadError', 'labels', 'CarWash'), retryLabel: this.translate('retry', 'labels', 'CarWash')}));
                $body.find('.action-retry').on('click', this.load.bind(this));
            }.bind(this));
        },

        origin: function () { return window.location.origin; },

        kioskUrl: function () { return this.origin() + this.data.kioskPath; },

        fmt: function (v) {
            return v ? this.getDateTime().toDisplay(v) : this.L('never');
        },

        paint: function () {
            var d = this.data;
            var h = '';

            // --- status
            h += '<div class="cw-card-block"><h3>' + esc(this.L('statusTitle')) + '</h3>' +
                '<div class="cw-flex cw-flex--wide">' +
                '<div class="cw-grow">' +
                '<p><span class="cw-chip ' + (d.moduleEnabled ? 'cw-chip--done' : 'cw-chip--unpaid') + '">' +
                esc(d.moduleEnabled ? this.L('moduleOn') : this.L('moduleOff')) + '</span></p>' +
                '<p class="cw-meta">' + esc(this.L('kioskLink')) + '</p>' +
                '<p><a class="cw-strong" href="' + esc(this.kioskUrl()) + '" target="_blank" rel="noopener">' + esc(this.kioskUrl()) + '</a></p>' +
                '<div class="cw-actions"><a class="cw-btn cw-btn--primary" href="' + esc(this.kioskUrl()) + '" target="_blank" rel="noopener">' + esc(this.L('openKiosk')) + '</a>' +
                '<button type="button" class="cw-btn kn-copy" data-text="' + esc(this.kioskUrl()) + '">' + esc(this.L('copy')) + '</button></div>' +
                '</div><div class="cw-qr kn-qr-url" role="img" aria-label="' + esc(this.L('qrAlt')) + '"></div></div></div>';

            // --- devices
            h += '<div class="cw-card-block"><h3>' + esc(this.L('devicesTitle')) + '</h3>';
            h += '<div class="cw-flex"><input type="text" class="form-control kn-device-name cw-input-wide" maxlength="60" placeholder="' + esc(this.L('deviceNameHint')) + '">' +
                '<button type="button" class="cw-btn cw-btn--primary kn-issue">' + esc(this.L('issueDevice')) + '</button></div>';
            h += '<div class="kn-issued"></div>';

            if (!d.devices.length) {
                h += '<p class="cw-meta">' + esc(this.L('deviceNone')) + '</p>';
            } else {
                h += '<table class="cw-table"><thead><tr><th>' + esc(this.L('deviceCol')) + '</th><th>' + esc(this.L('lastSeen')) + '</th><th></th></tr></thead><tbody>';
                d.devices.forEach(function (dev) {
                    h += '<tr><td><span class="cw-strong">' + esc(dev.name) + '</span><div class="cw-meta">' + esc(dev.userName) + '</div></td>' +
                        '<td>' + esc(this.fmt(dev.lastSeenAt)) + '</td><td class="cw-right">' +
                        (dev.active
                            ? '<span class="cw-chip cw-chip--done">' + esc(this.L('active')) + '</span> <button type="button" class="cw-btn kn-revoke" data-id="' + esc(dev.id) + '">' + esc(this.L('revoke')) + '</button>'
                            : '<span class="cw-chip cw-chip--cancel">' + esc(this.L('inactive')) + '</span>') +
                        '</td></tr>';
                }.bind(this));
                h += '</tbody></table>';
            }
            h += '</div>';

            // --- staff
            h += '<div class="cw-card-block"><h3>' + esc(this.L('staffTitle')) + '</h3><table class="cw-table"><thead><tr><th>' + esc(this.L('employee')) +
                '</th><th>' + esc(this.L('language')) + '</th><th>' + esc(this.L('pinStatus')) + '</th><th></th></tr></thead><tbody>';
            d.staff.forEach(function (s) {
                var chip = s.pin.status === 'none'
                    ? '<span class="cw-chip cw-chip--unpaid">' + esc(this.L('pinNone')) + '</span>'
                    : (s.pin.status === 'locked'
                        ? '<span class="cw-chip cw-chip--wait">' + esc(this.L('pinLocked')) + ' ' + esc(this.fmt(s.pin.lockedUntil)) + '</span>'
                        : '<span class="cw-chip cw-chip--done">' + esc(this.L('pinSet')) + '</span>');

                h += '<tr><td class="cw-strong">' + esc(s.name) + '</td><td>' + esc(s.language.slice(0, 2).toUpperCase()) + '</td><td>' + chip + '</td><td class="cw-right">' +
                    (s.pin.status === 'locked' ? '<button type="button" class="cw-btn kn-unlock" data-id="' + esc(s.id) + '">' + esc(this.L('resetLock')) + '</button> ' : '') +
                    '<button type="button" class="cw-btn kn-setpin" data-id="' + esc(s.id) + '" data-name="' + esc(s.name) + '">' + esc(this.L('setPin')) + '</button></td></tr>';
            }.bind(this));
            h += '</tbody></table></div>';

            // --- settings
            var st = d.settings;
            var field = function (name, label, val, min, max) {
                return '<label class="cw-field"><span class="cw-meta">' + esc(label) + '</span><input type="number" class="form-control kn-set" data-name="' + name +
                    '" min="' + min + '" max="' + max + '" value="' + esc(val) + '"></label>';
            };

            h += '<div class="cw-card-block"><h3>' + esc(this.L('settingsTitle')) + '</h3><div class="cw-fields">' +
                '<label class="cw-field cw-field--check"><input type="checkbox" class="kn-module" ' + (d.moduleEnabled ? 'checked' : '') + '><span class="cw-strong">' + esc(this.L('moduleSwitch')) + '</span></label>' +
                field('kioskIdleSeconds', this.translate('kioskIdleSeconds', 'fields', 'Settings'), st.kioskIdleSeconds, 15, 900) +
                field('kioskSessionHours', this.translate('kioskSessionHours', 'fields', 'Settings'), st.kioskSessionHours, 1, 24) +
                field('kioskPinMaxAttempts', this.translate('kioskPinMaxAttempts', 'fields', 'Settings'), st.kioskPinMaxAttempts, 3, 20) +
                field('kioskPinLockMinutes', this.translate('kioskPinLockMinutes', 'fields', 'Settings'), st.kioskPinLockMinutes, 1, 1440) +
                field('kioskRateLimitPerMinute', this.translate('kioskRateLimitPerMinute', 'fields', 'Settings'), st.kioskRateLimitPerMinute, 10, 1000) +
                field('photoRetentionDays', this.translate('photoRetentionDays', 'fields', 'Settings'), st.photoRetentionDays, 1, 3650) +
                '</div><div class="cw-actions"><button type="button" class="cw-btn cw-btn--primary kn-save">' + esc(this.L('save')) + '</button></div></div>';

            // --- links
            h += '<div class="cw-flex cw-flex--wide"><a class="cw-btn" href="#KioskEvent">' + esc(this.L('auditLink')) + ' (' + d.today.events + ', ' + d.today.refused + ')</a>' +
                '<a class="cw-btn" href="#KioskSession">' + esc(this.L('sessionsLink')) + '</a>' +
                '<a class="cw-btn" href="#OrderPhoto">' + esc(this.L('photosLink')) + '</a></div>';

            this.$el.find('.kn-body').html(h);
            this.qr(this.kioskUrl(), this.$el.find('.kn-qr-url'));
        },

        qr: function (text, $target) {
            Espo.Ajax.postRequest('Kiosk/action/qr', {text: text}).then(function (res) {
                $target.html(res && res.svg ? res.svg : '');
            });
        },

        copy: function (text) {
            var done = function () { Espo.Ui.success(this.L('copied')); }.bind(this);

            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); return; }

            var $t = $('<textarea>').val(text).appendTo(document.body);
            $t[0].select();
            document.execCommand('copy');
            $t.remove();
            done();
        },

        issue: function () {
            var name = String(this.$el.find('.kn-device-name').val() || '').trim();

            if (!name) { this.$el.find('.kn-device-name').focus(); return; }

            Espo.Ajax.postRequest('Kiosk/action/issueDevice', {name: name}).then(function (res) {
                var url = this.origin() + res.kioskPath + res.provisionFragment;

                this.$el.find('.kn-issued').html(
                    '<div class="cw-keybox" role="status"><div class="cw-flex cw-flex--wide"><div class="cw-grow">' +
                    '<h4>' + esc(this.L('deviceIssued')) + ': ' + esc(res.name) + '</h4>' +
                    '<p class="cw-neg cw-strong">' + esc(this.L('deviceKeyOnce')) + '</p>' +
                    '<p class="cw-meta">' + esc(this.L('deviceKey')) + '</p><p><code class="cw-key">' + esc(res.apiKey) + '</code></p>' +
                    '<p class="cw-meta">' + esc(this.L('deviceProvisionLink')) + '</p><p><code class="cw-key">' + esc(url) + '</code></p>' +
                    '<div class="cw-actions"><button type="button" class="cw-btn kn-copy" data-text="' + esc(res.apiKey) + '">' + esc(this.L('copy')) + ' ' + esc(this.L('deviceKey')) + '</button>' +
                    '<button type="button" class="cw-btn kn-copy" data-text="' + esc(url) + '">' + esc(this.L('copy')) + ' ' + esc(this.L('deviceProvisionLink')) + '</button></div>' +
                    '</div><div class="cw-qr kn-qr-key" role="img" aria-label="' + esc(this.L('qrAlt')) + '"></div></div></div>');
                this.qr(url, this.$el.find('.kn-qr-key'));

                // refresh the list, but keep the key panel (it is shown once)
                var keep = this.$el.find('.kn-issued').html();
                Espo.Ajax.postRequest('Kiosk/action/adminOverview', {}).then(function (data) {
                    this.data = data;
                    this.paint();
                    this.$el.find('.kn-issued').html(keep);
                    this.qr(url, this.$el.find('.kn-qr-key'));
                }.bind(this));
            }.bind(this)).catch(function () { Espo.Ui.error(this.L('error')); }.bind(this));
        },

        revoke: function (id) {
            this.confirm(this.L('revokeConfirm')).then(function () {
                Espo.Ajax.postRequest('Kiosk/action/revokeDevice', {deviceId: id}).then(function () {
                    Espo.Ui.success(this.L('revoked'));
                    this.load();
                }.bind(this));
            }.bind(this));
        },

        setPin: function (id, name) {
            this.createView('setPin', 'custom:views/employee/modals/set-pin', {employeeId: id, name: name}, function (view) {
                view.render();
                this.listenToOnce(view, 'done', this.load.bind(this));
            }.bind(this));
        },

        unlock: function (id) {
            Espo.Ajax.postRequest('Kiosk/action/unlockPin', {employeeId: id}).then(function () {
                Espo.Ui.success(this.L('unlocked'));
                this.load();
            }.bind(this));
        },

        saveSettings: function () {
            var payload = {};

            this.$el.find('.kn-set').each(function () { payload[$(this).data('name')] = parseInt($(this).val(), 10); });
            // Merge into the saved list: the other modules (booking, notify, assistant, anpr, cameras) are not this page's to switch.
            var all = ['kiosk', 'notify', 'booking', 'anpr', 'cameras']; // the AI assistants are off until switched on on purpose
            var list = this.data.settings.enabledModules == null ? all.slice() : this.data.settings.enabledModules.slice();
            var on = this.$el.find('.kn-module').is(':checked'), at = list.indexOf('kiosk');
            if (on && at < 0) { list.push('kiosk'); } else if (!on && at >= 0) { list.splice(at, 1); }
            payload.enabledModules = list;

            Espo.Ajax.putRequest('Settings', payload).then(function () {
                Espo.Ui.success(this.L('saved'));
                this.load();
            }.bind(this)).catch(function () { Espo.Ui.notify(false); });
        },
    });
});
