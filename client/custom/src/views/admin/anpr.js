/**
 * Administration -> Car wash -> Plates and cameras (#Admin/anpr). One page for the plate-recognition
 * and camera modules:
 *   - module switches (merged into Settings.enabledModules - the other modules are never touched),
 *   - edge nodes: "Issue a node" shows a ONE-TIME enrolment token (text + QR), revoke, heartbeat, queue,
 *   - cameras and their health, a test event that runs the real pipeline,
 *   - thresholds, auto-create (OFF by default), retention (30 / 90 days by default - check with a lawyer),
 *   - the data subject's request: find a plate, delete every event and picture of it for good,
 *   - the nightly clean-up on demand, and links to the journals.
 * Everything comes from Anpr/action/adminOverview.
 */
define(['view', 'custom:lib/state'], function (View, State) {

    var esc = State.esc;
    var ALL = ['kiosk', 'notify', 'booking', 'assistant', 'anpr', 'cameras'];

    return View.extend({

        templateContent: '<div class="an-body cw-pad"></div>',

        events: {
            'click .an-issue': function () { this.issue(); },
            'click .an-revoke': function (e) { this.revoke($(e.currentTarget).data('id')); },
            'click .an-copy': function (e) { this.copy($(e.currentTarget).data('text')); },
            'click .an-test': function () { this.testEvent(); },
            'click .an-save': function () { this.saveSettings(); },
            'click .an-erase-search': function () { this.eraseSearch(); },
            'click .an-erase-do': function () { this.eraseDo(); },
            'click .an-purge': function (e) { this.purge(!!$(e.currentTarget).data('dry')); },
        },

        L: function (key) { return this.translate(key, 'labels', 'Anpr'); },

        afterRender: function () { this.load(); },

        load: function () {
            var $b = this.$el.find('.an-body');

            if (!this.data) { $b.html(State.loading(this.L('loading'))); }

            Espo.Ajax.postRequest('Anpr/action/adminOverview', {}).then(function (d) {
                this.data = d;
                this.paint();
            }.bind(this)).catch(function () {
                $b.html(State.error({title: this.L('loadError'), retryLabel: this.L('retry')}));
                $b.find('.action-retry').on('click', this.load.bind(this));
            }.bind(this));
        },

        fmt: function (v) { return v ? this.getDateTime().toDisplay(v) : this.L('never'); },

        chip: function (cls, text) { return '<span class="cw-chip ' + cls + '">' + esc(text) + '</span>'; },

        paint: function () {
            var d = this.data, st = d.settings, h = '';
            var num = function (name, min, max, step) {
                return '<label class="cw-field"><span class="cw-meta">' + esc(this.translate(name, 'fields', 'Settings')) + '</span>' +
                    '<input type="number" class="form-control an-set" data-name="' + name + '" min="' + min + '" max="' + max + '" step="' + (step || 1) + '" value="' + esc(st[name]) + '"></label>';
            }.bind(this);
            var chk = function (name) {
                return '<label class="cw-field cw-field--check"><input type="checkbox" class="an-set" data-name="' + name + '" data-type="bool"' + (st[name] ? ' checked' : '') + '>' +
                    '<span class="cw-strong">' + esc(this.translate(name, 'fields', 'Settings')) + '</span></label>';
            }.bind(this);

            // --- modules
            h += '<div class="cw-card-block"><h3>' + esc(this.L('statusTitle')) + '</h3><div class="cw-fields">';
            ['anpr', 'cameras'].forEach(function (m) {
                var on = d.modules[m];
                h += '<label class="cw-field cw-field--check"><input type="checkbox" class="an-module" data-module="' + m + '"' + (on ? ' checked' : '') + '>' +
                    '<span class="cw-strong">' + esc(this.L(m === 'anpr' ? 'moduleAnpr' : 'moduleCameras')) + '</span> ' +
                    this.chip(on ? 'cw-chip--done' : 'cw-chip--unpaid', this.L(on ? 'moduleOn' : 'moduleOff')) + '</label>';
            }, this);
            h += '</div><p class="cw-meta">' + esc(this.L('todayTitle')) + ': ' + esc(this.L('todayEvents')) + ' ' + d.today.events + ' · ' + esc(this.L('todayPending')) + ' ' + d.today.pending +
                ' · ' + esc(this.L('todayAuto')) + ' ' + d.today.autoCreated + ' · ' + esc(this.L('todayViews')) + ' ' + d.today.views + '</p></div>';

            // --- nodes
            h += '<div class="cw-card-block"><h3>' + esc(this.L('nodesTitle')) + '</h3>' +
                '<div class="cw-flex"><input type="text" class="form-control an-node-name cw-input-wide" maxlength="60" placeholder="' + esc(this.L('nodeNameHint')) + '">' +
                '<button type="button" class="cw-btn cw-btn--primary an-issue">' + esc(this.L('issueNode')) + '</button></div><div class="an-issued"></div>';

            if (!d.nodes.length) { h += '<p class="cw-meta">' + esc(this.L('nodeNone')) + '</p>'; } else {
                h += '<table class="cw-table"><thead><tr><th>' + esc(this.L('nodeCol')) + '</th><th>' + esc(this.L('statusCol')) + '</th><th>' + esc(this.L('lastSeen')) + '</th><th>' +
                    esc(this.L('versionCol')) + '</th><th>' + esc(this.L('queueCol')) + '</th><th></th></tr></thead><tbody>';
                d.nodes.forEach(function (n) {
                    var s = n.status === 'Pending' ? this.chip('cw-chip--wait', this.L('nodePending'))
                        : (n.status === 'Revoked' ? this.chip('cw-chip--cancel', this.L('nodeRevoked'))
                            : this.chip(n.online ? 'cw-chip--done' : 'cw-chip--unpaid', this.L(n.online ? 'online' : 'offline')));
                    h += '<tr><td><span class="cw-strong">' + esc(n.name) + '</span></td><td>' + s + '</td><td>' + esc(this.fmt(n.lastSeenAt)) + '</td><td>' + esc(n.version || '') +
                        '</td><td>' + (n.queueDepth ? esc(n.queueDepth) : '0') + '</td><td class="cw-right">' +
                        (n.status !== 'Revoked' ? '<button type="button" class="cw-btn an-revoke" data-id="' + esc(n.id) + '">' + esc(this.L('revoke')) + '</button>' : '') + '</td></tr>';
                }, this);
                h += '</tbody></table>';
            }
            h += '</div>';

            // --- cameras + test event
            h += '<div class="cw-card-block"><h3>' + esc(this.L('camerasTitle')) + '</h3>';
            if (!d.cameras.length) { h += '<p class="cw-meta">' + esc(this.L('cameraNone')) + '</p>'; } else {
                h += '<table class="cw-table"><thead><tr><th>' + esc(this.L('cameraCol')) + '</th><th>' + esc(this.L('roleCol')) + '</th><th>' + esc(this.L('nodeCol')) + '</th><th>' +
                    esc(this.L('statusCol')) + '</th><th>' + esc(this.L('frameCol')) + '</th><th>' + esc(this.L('secretCol')) + '</th></tr></thead><tbody>';
                d.cameras.forEach(function (c) {
                    h += '<tr><td><a class="cw-strong" href="#Camera/view/' + esc(c.id) + '">' + esc(c.name) + '</a><div class="cw-meta">' + esc(c.code) + '</div></td><td>' +
                        esc(this.getLanguage().translateOption(c.role, 'role', 'Camera')) + '</td><td>' +
                        esc(c.edgeNodeName || '—') + '</td><td>' + this.chip(c.online ? 'cw-chip--done' : 'cw-chip--unpaid', this.L(c.online ? 'online' : 'offline')) +
                        (c.lastError ? '<div class="cw-meta">' + esc(c.lastError) + '</div>' : '') + '</td><td>' + esc(this.fmt(c.lastFrameAt)) + '</td><td>' + esc(this.L(c.secretSet ? 'secretSet' : 'secretNone')) + '</td></tr>';
                }, this);
                h += '</tbody></table>';
            }
            h += '<div class="cw-actions"><a class="cw-btn" href="#Camera/create">' + esc(this.L('addCamera')) + '</a></div>';

            h += '<h4>' + esc(this.L('testTitle')) + '</h4><p class="cw-meta">' + esc(this.L('testHint')) + '</p>' +
                '<div class="cw-fields"><label class="cw-field"><span class="cw-meta">' + esc(this.L('cameraCol')) + '</span><select class="form-control an-test-camera">' +
                d.cameras.map(function (c) { return '<option value="' + esc(c.code) + '">' + esc(c.name) + '</option>'; }).join('') + '</select></label>' +
                '<label class="cw-field"><span class="cw-meta">' + esc(this.L('testPlate')) + '</span><input type="text" class="form-control cw-input-plate an-test-plate" maxlength="12" value="TEST123"></label>' +
                '<label class="cw-field"><span class="cw-meta">' + esc(this.L('testConfidence')) + '</span><input type="number" class="form-control an-test-conf" min="0" max="1" step="0.01" value="0.95"></label></div>' +
                '<div class="cw-actions"><button type="button" class="cw-btn an-test">' + esc(this.L('testSend')) + '</button></div></div>';

            // --- settings
            h += '<div class="cw-card-block"><h3>' + esc(this.L('settingsTitle')) + '</h3><h4>' + esc(this.L('thresholdsTitle')) + '</h4><div class="cw-fields">' +
                chk('anprAutoCreate') + '<p class="cw-meta">' + esc(this.L('autoCreateWarn')) + '</p>' +
                num('anprMinConfidence', 0, 1, 0.01) + num('anprHighConfidence', 0, 1, 0.01) + num('anprDedupSeconds', 5, 3600) + num('anprRateLimitPerMinute', 10, 6000) +
                num('anprMaxEventAgeHours', 1, 336) + num('anprPollSeconds', 2, 60) + chk('anprAlertSound') + '</div>' +
                '<h4>' + esc(this.L('retentionTitle')) + '</h4><p class="cw-meta">' + esc(this.L('retentionNote')) + '</p><div class="cw-fields">' +
                num('anprRetentionDays', 1, 3650) + num('anprEventRetentionDays', 1, 3650) + num('anprAccessLogDays', 30, 3650) + num('camerasFrameKeepHours', 1, 336) +
                '<label class="cw-field"><span class="cw-meta">' + esc(this.translate('camerasViewBase', 'fields', 'Settings')) + '</span><input type="text" class="form-control an-set" data-name="camerasViewBase" data-type="text" maxlength="120" value="' + esc(st.camerasViewBase) + '"></label>' +
                '</div><div class="cw-actions"><button type="button" class="cw-btn cw-btn--primary an-save">' + esc(this.L('save')) + '</button></div></div>';

            // --- privacy: erasure + purge
            h += '<div class="cw-card-block"><h3>' + esc(this.L('eraseTitle')) + '</h3><p class="cw-meta">' + esc(this.L('eraseHint')) + '</p>' +
                '<div class="cw-flex"><input type="text" class="form-control cw-input-plate an-erase-plate" maxlength="12" placeholder="' + esc(this.L('erasePlate')) + '">' +
                '<button type="button" class="cw-btn an-erase-search">' + esc(this.L('eraseSearch')) + '</button></div><div class="an-erase-out"></div>' +
                '<h4>' + esc(this.L('purgeTitle')) + '</h4><p class="cw-meta">' + esc(this.L('purgeHint')) + '</p><div class="cw-actions">' +
                '<button type="button" class="cw-btn an-purge" data-dry="1">' + esc(this.L('purgeDry')) + '</button>' +
                '<button type="button" class="cw-btn an-purge">' + esc(this.L('purgeRun')) + '</button></div><div class="an-purge-out cw-meta"></div></div>';

            // --- links
            h += '<div class="cw-card-block"><h3>' + esc(this.L('linksTitle')) + '</h3><div class="cw-flex cw-flex--wide"><a class="cw-btn" href="#PlateEvent">' + esc(this.L('eventsLink')) + '</a>' +
                '<a class="cw-btn" href="#MediaAccess">' + esc(this.L('accessLink')) + '</a><a class="cw-btn" href="#CameraCommand">' + esc(this.L('commandsLink')) + '</a>' +
                '<a class="cw-btn" href="#Camera">' + esc(this.L('camerasLink')) + '</a></div><p class="cw-meta">' + esc(this.L('noticeLink')) + '</p></div>';

            this.$el.find('.an-body').html(h);
            this.$el.find('.an-module').on('change', this.saveModules.bind(this));
        },

        apiError: function (xhr) {
            if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
            var code = '';
            try { code = (xhr.responseJSON || JSON.parse(xhr.responseText)).error; } catch (e) { /* none */ }
            var t = code ? this.translate(code, 'errors', 'Anpr') : '';
            Espo.Ui.error(t && t !== code ? t : this.L('error'));
        },

        qr: function (text, $target) {
            Espo.Ajax.postRequest('Kiosk/action/qr', {text: text}).then(function (res) { $target.html(res && res.svg ? res.svg : ''); });
        },

        copy: function (text) {
            var done = function () { Espo.Ui.success(this.L('copied')); }.bind(this);
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); return; }
            var $t = $('<textarea>').val(text).appendTo(document.body);
            $t[0].select(); document.execCommand('copy'); $t.remove(); done();
        },

        // ---- modules: merge into the saved list, never replace the other modules
        /** The saved module list with `changes` ({anpr: true, cameras: false}) applied; the other modules are untouched. null = every module is on. */
        mergeModules: function (cur, changes) {
            var list = cur == null ? ALL.slice() : cur.slice();

            Object.keys(changes).forEach(function (m) {
                var i = list.indexOf(m);
                if (changes[m] && i < 0) { list.push(m); } else if (!changes[m] && i >= 0) { list.splice(i, 1); }
            });

            return list;
        },

        saveModules: function () {
            var changes = {};

            this.$el.find('.an-module').each(function () { changes[$(this).data('module')] = $(this).is(':checked'); });

            Espo.Ajax.putRequest('Settings', {enabledModules: this.mergeModules(this.data.enabledModules, changes)}).then(function () { Espo.Ui.success(this.L('saved')); this.load(); }.bind(this))
                .catch(function () { Espo.Ui.notify(false); });
        },

        // ---- nodes
        issue: function () {
            var name = String(this.$el.find('.an-node-name').val() || '').trim();
            if (!name) { this.$el.find('.an-node-name').focus(); return; }

            Espo.Ajax.postRequest('Anpr/action/adminIssueNode', {name: name}).then(function (res) {
                var cmd = 'python -m cwedge register --crm ' + window.location.origin + ' --token ' + res.enrollToken;
                var html = '<div class="cw-keybox" role="status"><div class="cw-flex cw-flex--wide"><div class="cw-grow"><h4>' + esc(this.L('nodeIssued')) + ': ' + esc(res.name) + '</h4>' +
                    '<p class="cw-neg cw-strong">' + esc(this.L('tokenOnce')) + '</p><p class="cw-meta">' + esc(this.L('enrollToken')) + '</p><p><code class="cw-key">' + esc(res.enrollToken) + '</code></p>' +
                    '<p class="cw-meta">' + esc(this.L('enrollCommand')) + '</p><p><code class="cw-key">' + esc(cmd) + '</code></p>' +
                    '<div class="cw-actions"><button type="button" class="cw-btn an-copy" data-text="' + esc(res.enrollToken) + '">' + esc(this.L('copy')) + '</button>' +
                    '<button type="button" class="cw-btn an-copy" data-text="' + esc(cmd) + '">' + esc(this.L('enrollCommand')) + ' — ' + esc(this.L('copy')) + '</button></div></div>' +
                    '<div class="cw-qr an-qr" role="img" aria-label="' + esc(this.L('qrAlt')) + '"></div></div></div>';

                Espo.Ajax.postRequest('Anpr/action/adminOverview', {}).then(function (d) {
                    this.data = d;
                    this.paint();
                    this.$el.find('.an-issued').html(html);   // shown once: re-drawn after the list refresh
                    this.qr(res.enrollToken, this.$el.find('.an-qr'));
                }.bind(this));
            }.bind(this)).catch(this.apiError.bind(this));
        },

        revoke: function (id) {
            this.confirm(this.L('revokeConfirm')).then(function () {
                Espo.Ajax.postRequest('Anpr/action/adminRevokeNode', {nodeId: id}).then(function () {
                    Espo.Ui.success(this.L('revoked'));
                    this.load();
                }.bind(this)).catch(this.apiError.bind(this));
            }.bind(this));
        },

        testEvent: function () {
            var code = this.$el.find('.an-test-camera').val();
            if (!code) { Espo.Ui.warning(this.L('testNoCamera')); return; }

            Espo.Ajax.postRequest('Anpr/action/testEvent', {
                camera: code, plate: this.$el.find('.an-test-plate').val(), confidence: parseFloat(this.$el.find('.an-test-conf').val()),
            }).then(function () { Espo.Ui.success(this.L('testSent')); this.load(); }.bind(this)).catch(this.apiError.bind(this));
        },

        // ---- settings
        saveSettings: function () {
            var payload = {};
            this.$el.find('.an-set').each(function () {
                var $i = $(this), n = $i.data('name'), t = $i.data('type');
                payload[n] = t === 'bool' ? $i.is(':checked') : (t === 'text' ? String($i.val()) : parseFloat($i.val()));
            });

            Espo.Ajax.putRequest('Settings', payload).then(function () { Espo.Ui.success(this.L('saved')); this.load(); }.bind(this))
                .catch(function () { Espo.Ui.notify(false); });
        },

        // ---- the data subject's request
        eraseSearch: function () {
            var plate = String(this.$el.find('.an-erase-plate').val() || '').trim();
            if (!plate) { return; }
            this.erasePlate = plate;

            Espo.Ajax.postRequest('Anpr/action/eraseSearch', {plate: plate}).then(function (r) {
                var $o = this.$el.find('.an-erase-out');
                if (!r.events && !r.cameraPhotos) { $o.html('<p class="cw-meta">' + esc(this.L('eraseNothing')) + '</p>'); return; }
                $o.html('<p><b>' + esc(r.plate) + '</b> — ' + esc(this.L('eraseFound')) + ': ' + r.events + ' ' + esc(this.L('eraseEvents')) + ', ' + r.snapshots + ' ' + esc(this.L('eraseSnapshots')) + ', ' +
                    r.cameraPhotos + ' ' + esc(this.L('eraseCameraPhotos')) + '</p>' +
                    '<label class="cw-field cw-field--check"><input type="checkbox" class="an-erase-photos"><span>' + esc(this.L('eraseIncludePhotos')) + '</span></label>' +
                    '<div class="cw-actions"><button type="button" class="cw-btn cw-btn--primary an-erase-do">' + esc(this.L('eraseDo')) + '</button></div>');
            }.bind(this)).catch(this.apiError.bind(this));
        },

        eraseDo: function () {
            var photos = this.$el.find('.an-erase-photos').is(':checked');
            this.confirm(this.L('eraseConfirm')).then(function () {
                Espo.Ajax.postRequest('Anpr/action/erase', {plate: this.erasePlate, includeOrderPhotos: photos}).then(function (r) {
                    Espo.Ui.success(this.L('eraseDone') + ': ' + r.events + ' + ' + r.cameraPhotos);
                    this.$el.find('.an-erase-out').empty();
                    this.$el.find('.an-erase-plate').val('');
                    this.load();
                }.bind(this)).catch(this.apiError.bind(this));
            }.bind(this));
        },

        purge: function (dry) {
            Espo.Ajax.postRequest('Anpr/action/purge', {dryRun: dry}).then(function (r) {
                this.$el.find('.an-purge-out').text(this.L('purged') + (dry ? ' (dry)' : '') + ': snapshots ' + r.snapshots + ', events ' + r.events + ', frames ' + r.frames + ', log ' + r.accessLog);
            }.bind(this)).catch(this.apiError.bind(this));
        },
    });
});
