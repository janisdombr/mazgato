/**
 * Administration → Car wash → Quick start: the whole catalogue in ONE editor instead of hand-made many-to-many links.
 *   1 Staff (roles + employees)  ->  2 Services (a row per service; open it to see its works, role and rate in EUR)
 *   ->  3 Works and their base rate  ->  4 Check (what is still missing, each item is a link to the row).
 * Saved through QuickStart/action/save in one transaction. Logic without DOM: custom:lib/quick-start. Guide: docs/QUICKSTART.md.
 */
define(['view', 'custom:lib/state', 'custom:lib/quick-start'], function (View, State, QS) {

    var esc = State.esc;

    return View.extend({

        templateContent: '<div class="ap-body cw-pad"></div>',

        events: {
            'click .qs-step': function (e) { this.scrollTo('.qs-' + $(e.currentTarget).data('step')); },
            'click .qs-save': function () { this.save(); },
            'click .qs-reload': function () { this.load(); },
            'click .qs-example': function () { this.loadExample(); },
            'click .qs-recheck': function () { this.recheck(); },
            'click .qs-goto': function (e) { this.focusItem($(e.currentTarget).data('focus')); },
            'click .qs-add': function (e) { this.add($(e.currentTarget).data('kind'), $(e.currentTarget).data('cat')); },
            'click .qs-del': function (e) { this.del($(e.currentTarget).data('list'), String($(e.currentTarget).data('ref'))); },
            'click .qs-toggle': function (e) { this.toggle(String($(e.currentTarget).data('ref'))); },
            'click .qs-line-del': function (e) { this.lineDel(String($(e.currentTarget).data('ref')), parseInt($(e.currentTarget).data('i'), 10)); },
            'change .qs-line-add': function (e) { this.lineAdd(String($(e.currentTarget).data('ref')), $(e.currentTarget).val()); },
            'change .qs-in': function (e) { this.edit($(e.currentTarget)); },
            'click .qs-hint-toggle': function () { this.$el.find('.qs-hint').toggleClass('hidden'); },
        },

        L: function (key, section) { return this.translate(key, section || 'labels', 'QuickStart'); },

        setup: function () {
            this.model_ = null;
            this.open = {};
            this.issues = null;
        },

        afterRender: function () {
            this.$el.find('.ap-body').html(State.loading(this.L('loading')));
            this.load();
        },

        // ------------------------------------------------------------ data

        load: function () {
            return Espo.Ajax.postRequest('QuickStart/action/state', {}).then(function (d) {
                this.model_ = d;
                this.paint();
                this.check();
            }.bind(this), function (xhr) {
                this.$el.find('.ap-body').html(State.error({auth: xhr && (xhr.status === 401 || xhr.status === 403), title: this.L('loadError'),
                    retryLabel: this.L('reload'), loginLabel: this.L('reload')}));
            }.bind(this));
        },

        check: function () {
            return Espo.Ajax.postRequest('QuickStart/action/check', {}).then(function (d) {
                this.issues = d;
                this.checkedAt = new Date();
                this.paintCheck();
            }.bind(this));
        },

        // "Check again" must reflect what is on screen: the check reads SAVED data, so unsaved edits are saved first
        // (validation errors stop it with the usual message), then the result is shown with the time it was taken.
        recheck: function () {
            var self = this;
            var $b = this.$el.find('.qs-recheck').addClass('is-busy').prop('disabled', true);

            if (this.model_ && QS.validate(this.model_).length) {
                $b.removeClass('is-busy').prop('disabled', false);
                this.save();

                return;
            }

            var p = this.model_
                ? Espo.Ajax.postRequest('QuickStart/action/save', QS.buildPayload(this.model_)).then(function (d) { self.model_ = d.state; self.paint(); })
                : $.Deferred().resolve().promise();

            p.then(function () { return self.check(); }).always(function () {
                self.$el.find('.qs-recheck').removeClass('is-busy').prop('disabled', false);
            });
        },

        save: function () {
            var bad = QS.validate(this.model_);

            if (bad.length) {
                Espo.Ui.error(this.L('fixFirst') + ': ' + this.L('v_' + bad[0].code, 'validation'));
                this.markBad(bad);

                return;
            }

            var $b = this.$el.find('.qs-save').addClass('is-busy');

            // a refusal (400 / 409) is shown by the framework with the server's reason; the model stays as typed
            Espo.Ajax.postRequest('QuickStart/action/save', QS.buildPayload(this.model_)).then(function (d) {
                this.model_ = d.state;
                this.paint();
                this.check();
                Espo.Ui.success(this.L('saved'));
            }.bind(this), function () {
                $b.removeClass('is-busy');
            });
        },

        loadExample: function () {
            if (!window.confirm(this.L('confirmExample'))) { return; }

            Espo.Ajax.postRequest('QuickStart/action/loadExample', {}).then(function (d) {
                var n = 0;

                Object.keys(d.created || {}).forEach(function (k) { n += d.created[k]; });
                this.model_ = d.state;
                this.paint();
                this.check();
                Espo.Ui.success(n ? this.L('exampleLoaded').replace('{n}', n) : this.L('exampleNothing'));
            }.bind(this));
        },

        // ------------------------------------------------------------ model edits

        list: function (name) { return this.model_[name]; },

        find: function (list, ref) {
            return (this.model_[list] || []).filter(function (it) { return (it.id || it.key) === ref; })[0];
        },

        edit: function ($in) {
            var list = $in.data('list'), ref = String($in.data('ref')), f = $in.data('f'), it = this.find(list, ref);

            if (!it) { return; }

            var v = $in.is(':checkbox') ? $in.prop('checked') : $in.val();

            if (f === '_line') { // services[].lines[i].<sub>
                var l = it.lines[parseInt($in.data('i'), 10)];

                l[$in.data('sub')] = v === '' ? null : v;
            } else if (f === 'active') { // employee: Hired <-> Dismissed
                it.status = v ? 'Hired' : 'Dismissed';
            } else {
                it[f] = (v === '' && /Id$/.test(f)) ? null : v;
            }

            it._dirty = true;
            $in.removeClass('has-error');

            if (/^(name|isActive|status|active)$/.test(f) || /Id$/.test(f)) { this.paintDeferred(); }

            this.updateSave();
        },

        paintDeferred: function () {
            clearTimeout(this._t);
            this._t = setTimeout(this.paint.bind(this), 0);
        },

        add: function (kind, cat) {
            var map = {employee: 'employees', role: 'roles', work: 'works', service: 'services'};
            var it = QS.blank[kind](cat);

            this.model_[map[kind]].push(it);

            if (kind === 'service') { this.open[it.key] = true; }

            this.paint();
            this.focusItem(kind + ':' + it.key);
        },

        del: function (list, ref) {
            var it = this.find(list, ref);

            if (!it) { return; }

            if (!it._new && !window.confirm(this.L('confirmDelete').replace('{name}', it.name || ''))) { return; }

            it._delete = true;
            this.paint();
        },

        toggle: function (ref) {
            this.open[ref] = !this.open[ref];
            this.paint();
        },

        lineAdd: function (ref, workId) {
            var s = this.find('services', ref);

            if (!s || !workId) { return; }

            s.lines.push(QS.blank.line(workId));
            s._dirty = true;
            this.open[ref] = true;
            this.paint();
        },

        lineDel: function (ref, i) {
            var s = this.find('services', ref);

            if (!s) { return; }

            s.lines.splice(i, 1);
            s._dirty = true;
            this.paint();
        },

        // ------------------------------------------------------------ rendering

        scrollTo: function (sel) {
            var $t = this.$el.find(sel).first();

            if ($t.length) { $t[0].scrollIntoView({behavior: 'smooth', block: 'start'}); }
        },

        focusItem: function (focus) {
            var f = QS.parseFocus(focus), map = {employee: 'employees', role: 'roles', work: 'works', service: 'services'};

            if (!f.id) { this.scrollTo('.qs-' + ({services: 'step2', employees: 'step1', roles: 'step1', works: 'step3'}[f.kind] || 'step1')); return; }

            if (f.kind === 'service') { this.open[f.id] = true; this.paint(); }

            var $r = this.$el.find('[data-row="' + f.kind + ':' + f.id + '"]').first();

            if (!$r.length) { return; }

            $r[0].scrollIntoView({behavior: 'smooth', block: 'center'});
            this.$el.find('.is-focus').removeClass('is-focus');
            $r.addClass('is-focus').find('input,select').first().trigger('focus');
        },

        markBad: function (bad) {
            bad.forEach(function (b) {
                this.$el.find('[data-list="' + b.list + '"][data-ref="' + b.ref + '"][data-f="' + b.field + '"]').addClass('has-error');
            }.bind(this));
        },

        updateSave: function () {
            this.$el.find('.qs-save').prop('disabled', !QS.isDirty(this.model_));
            this.$el.find('.qs-dirty').toggleClass('hidden', !QS.isDirty(this.model_));
        },

        input: function (list, it, f, val, extra) {
            var ref = it.id || it.key;

            return '<input class="form-control qs-in" data-list="' + list + '" data-ref="' + esc(ref) + '" data-f="' + f + '" value="' +
                esc(val == null ? '' : val) + '" ' + (extra || '') + '>';
        },

        select: function (list, it, f, val, options, blankLabel, extra) {
            var ref = it.id || it.key;

            return '<select class="form-control qs-in" data-list="' + list + '" data-ref="' + esc(ref) + '" data-f="' + f + '" ' + (extra || '') + '>' +
                '<option value="">' + esc(blankLabel || '') + '</option>' +
                options.map(function (o) {
                    return '<option value="' + esc(o.id || o.key) + '"' + ((o.id || o.key) === val ? ' selected' : '') + '>' + esc(o.name || '?') + '</option>';
                }).join('') + '</select>';
        },

        check_: function (list, it, f, on, label) {
            var ref = it.id || it.key;

            return '<input type="checkbox" class="qs-in" data-list="' + list + '" data-ref="' + esc(ref) + '" data-f="' + f + '"' + (on ? ' checked' : '') +
                ' aria-label="' + esc(label) + '">';
        },

        delBtn: function (list, it) {
            return '<button type="button" class="cw-btn qs-del" data-list="' + list + '" data-ref="' + esc(it.id || it.key) + '" title="' +
                esc(this.L('delete')) + '" aria-label="' + esc(this.L('delete')) + '"><i class="fas fa-trash"></i></button>';
        },

        paint: function () {
            var m = this.model_, self = this, L = this.L.bind(this);
            var live = function (arr) { return arr.filter(function (x) { return !x._delete; }); };
            var roles = live(m.roles), emps = live(m.employees), works = live(m.works);
            var hired = emps.filter(function (e) { return e.status === 'Hired'; });

            var head =
                '<section class="cw-section"><h3 class="cw-h2">' + esc(L('title')) + '</h3>' +
                '<p class="cw-meta">' + esc(L('intro')) + '</p>' +
                '<ol class="qs-steps">' + ['step1', 'step2', 'step3', 'step4'].map(function (s, i) {
                    return '<li><button type="button" class="cw-btn qs-step" data-step="' + s + '"><b>' + (i + 1) + '</b> ' + esc(L(s + 'Title')) + '</button></li>';
                }).join('') + '</ol>' +
                '<div class="cw-flex qs-toolbar">' +
                '<button type="button" class="cw-btn cw-btn--primary qs-save" disabled>' + esc(L('save')) + '</button>' +
                '<button type="button" class="cw-btn qs-reload">' + esc(L('reload')) + '</button>' +
                '<button type="button" class="cw-btn qs-example">' + esc(L('loadExample')) + '</button>' +
                '<span class="cw-meta qs-dirty hidden">' + esc(L('unsaved')) + '</span></div></section>' +
                '<section class="cw-section qs-step4"></section>';

            // ---- step 1: staff
            var empRows = emps.map(function (e) {
                return '<tr data-row="employee:' + esc(e.id || e.key) + '">' +
                    '<td>' + self.input('employees', e, 'name', e.name, 'placeholder="' + esc(L('employeeName')) + '"') + '</td>' +
                    '<td>' + self.select('employees', e, 'workRoleId', e.workRoleId, roles, L('noRole')) + '</td>' +
                    '<td class="qs-num">' + self.input('employees', e, 'salaryRate', e.salaryRate, 'inputmode="decimal"') + '</td>' +
                    '<td class="qs-c">' + self.check_('employees', e, 'active', e.status === 'Hired', L('active')) +
                    (e.status === 'OnLeave' ? ' <span class="cw-meta">' + esc(L('onLeave')) + '</span>' : '') + '</td>' +
                    '<td class="qs-c">' + self.delBtn('employees', e) + '</td></tr>';
            }).join('');
            var roleRows = roles.map(function (r) {
                return '<tr data-row="role:' + esc(r.id || r.key) + '">' +
                    '<td>' + self.input('roles', r, 'name', r.name) + '</td>' +
                    '<td>' + self.select('roles', r, 'defaultEmployeeId', r.defaultEmployeeId, hired, L('noEmployee')) + '</td>' +
                    '<td class="qs-c">' + self.check_('roles', r, 'isActive', r.isActive, L('active')) + '</td>' +
                    '<td class="qs-c">' + self.delBtn('roles', r) + '</td></tr>';
            }).join('');
            var s1 =
                '<section class="cw-section qs-step1"><h3 class="cw-h2"><b>1</b> ' + esc(L('step1Title')) + '</h3>' +
                '<p class="cw-meta">' + esc(L('step1Help')) + '</p>' +
                '<table class="cw-table"><thead><tr><th>' + esc(L('employeeName')) + '</th><th>' + esc(L('role')) + '</th><th>' + esc(L('salaryRate')) +
                '</th><th class="qs-c">' + esc(L('active')) + '</th><th></th></tr></thead><tbody>' + empRows + '</tbody></table>' +
                '<p><button type="button" class="cw-btn qs-add" data-kind="employee">+ ' + esc(L('addEmployee')) + '</button></p>' +
                '<h4 class="cw-section-title">' + esc(L('rolesTitle')) + '</h4><p class="cw-meta">' + esc(L('rolesHelp')) + '</p>' +
                '<table class="cw-table"><thead><tr><th>' + esc(L('role')) + '</th><th>' + esc(L('defaultEmployee')) + '</th><th class="qs-c">' + esc(L('active')) +
                '</th><th></th></tr></thead><tbody>' + roleRows + '</tbody></table>' +
                '<p><button type="button" class="cw-btn qs-add" data-kind="role">+ ' + esc(L('addRole')) + '</button></p></section>';

            // ---- step 2: services and add-ons
            var svcTable = function (cat) {
                var rows = live(m.services).filter(function (s) { return s.category === cat; }).map(function (s) {
                    var ref = s.id || s.key, open = !!self.open[ref];
                    var r = '<tr data-row="service:' + esc(ref) + '">' +
                        '<td><button type="button" class="cw-btn qs-toggle" data-ref="' + esc(ref) + '" aria-expanded="' + open + '" title="' + esc(L('works')) + '">' +
                        '<i class="fas fa-chevron-' + (open ? 'down' : 'right') + '"></i> ' + (s.lines || []).length + '</button></td>' +
                        '<td>' + self.input('services', s, 'name', s.name, 'placeholder="' + esc(L('serviceName')) + '"') + '</td>' +
                        '<td class="qs-num">' + self.input('services', s, 'priceRetail', s.priceRetail, 'inputmode="decimal"') + '</td>' +
                        '<td class="qs-num">' + self.input('services', s, 'priceCorporate', s.priceCorporate, 'inputmode="decimal"') + '</td>' +
                        '<td class="qs-num">' + self.input('services', s, 'durationMinutes', s.durationMinutes, 'inputmode="numeric"') + '</td>' +
                        '<td class="qs-c">' + self.check_('services', s, 'isActive', s.isActive, L('active')) + '</td>' +
                        '<td class="qs-c">' + self.delBtn('services', s) + '</td></tr>';

                    if (open) { r += '<tr class="qs-lines"><td></td><td colspan="6">' + self.linesBlock(s, works, roles) + '</td></tr>'; }

                    return r;
                }).join('');

                return '<table class="cw-table"><thead><tr><th>' + esc(L('works')) + '</th><th>' + esc(L('serviceName')) + '</th><th>' + esc(L('priceRetail')) +
                    ' €</th><th>' + esc(L('priceCorporate')) + ' €</th><th>' + esc(L('minutes')) + '</th><th class="qs-c">' + esc(L('active')) + '</th><th></th></tr></thead><tbody>' +
                    rows + '</tbody></table>';
            };
            var s2 =
                '<section class="cw-section qs-step2"><h3 class="cw-h2"><b>2</b> ' + esc(L('step2Title')) + '</h3>' +
                '<p class="cw-meta">' + esc(L('step2Help')) + '</p>' +
                '<p><button type="button" class="cw-btn qs-hint-toggle"><i class="fas fa-circle-question"></i> ' + esc(L('whatToEnter')) + '</button></p>' +
                '<div class="qs-hint hidden">' + esc(L('hintService')).replace(/\n/g, '<br>') + '</div>' +
                '<h4 class="cw-section-title">' + esc(L('baseServices')) + '</h4>' + svcTable('Base') +
                '<p><button type="button" class="cw-btn qs-add" data-kind="service" data-cat="Base">+ ' + esc(L('addService')) + '</button></p>' +
                '<h4 class="cw-section-title">' + esc(L('addons')) + '</h4>' + svcTable('Add-on') +
                '<p><button type="button" class="cw-btn qs-add" data-kind="service" data-cat="Add-on">+ ' + esc(L('addAddon')) + '</button></p></section>';

            // ---- step 3: works and base rate
            var workRows = works.map(function (w) {
                return '<tr data-row="work:' + esc(w.id || w.key) + '">' +
                    '<td>' + self.input('works', w, 'name', w.name) + '</td>' +
                    '<td>' + self.select('works', w, 'defaultRoleId', w.defaultRoleId, roles, L('noRole')) + '</td>' +
                    '<td class="qs-num">' + self.input('works', w, 'baseRate', w.baseRate, 'inputmode="decimal"') + '</td>' +
                    '<td class="qs-c">' + self.check_('works', w, 'isActive', w.isActive, L('active')) + '</td>' +
                    '<td class="qs-c">' + self.delBtn('works', w) + '</td></tr>';
            }).join('');
            var s3 =
                '<section class="cw-section qs-step3"><h3 class="cw-h2"><b>3</b> ' + esc(L('step3Title')) + '</h3>' +
                '<p class="cw-meta">' + esc(L('step3Help')) + '</p>' +
                '<table class="cw-table"><thead><tr><th>' + esc(L('work')) + '</th><th>' + esc(L('role')) + '</th><th>' + esc(L('baseRate')) +
                ' €</th><th class="qs-c">' + esc(L('active')) + '</th><th></th></tr></thead><tbody>' + workRows + '</tbody></table>' +
                '<p><button type="button" class="cw-btn qs-add" data-kind="work">+ ' + esc(L('addWork')) + '</button></p></section>';

            var keepFocus = this.$el.find(':focus').data('ref');

            this.$el.find('.ap-body').html(head + s1 + s2 + s3);
            this.paintCheck();
            this.updateSave();

            if (keepFocus) { this.$el.find('[data-ref="' + keepFocus + '"]').first().trigger('focus'); }
        },

        linesBlock: function (s, works, roles) {
            var self = this, L = this.L.bind(this), ref = s.id || s.key;
            var wname = function (id) { return (works.filter(function (w) { return (w.id || w.key) === id; })[0] || {}).name || '?'; };
            var wbase = function (id) { var w = works.filter(function (x) { return (x.id || x.key) === id; })[0]; return w && w.baseRate != null ? w.baseRate : ''; };
            var rows = (s.lines || []).map(function (l, i) {
                var sel = function (sub, val, opts, blank) {
                    return '<select class="form-control qs-in" data-list="services" data-ref="' + esc(ref) + '" data-f="_line" data-i="' + i + '" data-sub="' + sub + '">' +
                        '<option value="">' + esc(blank) + '</option>' + opts.map(function (o) {
                            return '<option value="' + esc(o.id || o.key) + '"' + ((o.id || o.key) === val ? ' selected' : '') + '>' + esc(o.name) + '</option>';
                        }).join('') + '</select>';
                };

                return '<tr><td>' + esc(wname(l.workId)) + '</td><td>' + sel('roleId', l.roleId, roles, L('roleFromWork')) + '</td>' +
                    '<td class="qs-num"><input class="form-control qs-in" inputmode="decimal" data-list="services" data-ref="' + esc(ref) + '" data-f="_line" data-i="' + i +
                    '" data-sub="rate" value="' + esc(l.rate == null ? '' : l.rate) + '" placeholder="' + esc(wbase(l.workId)) + '"></td>' +
                    '<td class="qs-c"><button type="button" class="cw-btn qs-line-del" data-ref="' + esc(ref) + '" data-i="' + i + '" aria-label="' + esc(L('delete')) +
                    '"><i class="fas fa-xmark"></i></button></td></tr>';
            }).join('');
            var free = QS.freeWorks(this.model_, s);

            return '<table class="cw-table"><thead><tr><th>' + esc(L('work')) + '</th><th>' + esc(L('role')) + '</th><th>' + esc(L('rate')) + ' €</th><th></th></tr></thead><tbody>' +
                (rows || '<tr><td colspan="4" class="cw-meta">' + esc(L('noWorksYet')) + '</td></tr>') + '</tbody></table>' +
                '<select class="form-control qs-line-add" data-ref="' + esc(ref) + '"><option value="">+ ' + esc(L('addWorkToService')) + '</option>' +
                free.map(function (w) { return '<option value="' + esc(w.id || w.key) + '">' + esc(w.name || '?') + '</option>'; }).join('') + '</select>' +
                '<p class="cw-meta">' + esc(L('rateHelp')) + '</p>';
        },

        checkedAtText: function () {
            var t = this.checkedAt;

            return t ? ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2) + ':' + ('0' + t.getSeconds()).slice(-2) : '';
        },

        paintCheck: function () {
            var $s = this.$el.find('.qs-step4');

            if (!$s.length) { return; }

            var d = this.issues, L = this.L.bind(this);

            if (!d) { $s.html('<h3 class="cw-h2"><b>4</b> ' + esc(L('step4Title')) + '</h3>' + State.loading(L('loading'))); return; }

            var self = this;
            var items = d.issues.slice().sort(function (a, b) { return (a.severity === 'error' ? 0 : 1) - (b.severity === 'error' ? 0 : 1); }).map(function (i) {
                var txt = self.translate(i.code, 'issues', 'QuickStart').replace('{name}', i.name || '');

                Object.keys(i.params || {}).forEach(function (k) { txt = txt.replace('{' + k + '}', i.params[k]); });

                return '<li class="qs-issue qs-issue--' + esc(i.severity) + '"><span class="cw-chip ' + (i.severity === 'error' ? 'cw-chip--cancel' : 'cw-chip--wait') + '">' +
                    esc(L(i.severity)) + '</span> ' + esc(txt) + ' <button type="button" class="cw-btn qs-goto" data-focus="' + esc(i.focus) + '">' + esc(L('goto')) + '</button>' +
                    (i.url ? ' <a href="' + esc(i.url) + '" class="cw-meta">' + esc(L('openRecord')) + '</a>' : '') + '</li>';
            }).join('');

            $s.html('<h3 class="cw-h2"><b>4</b> ' + esc(L('step4Title')) + '</h3>' +
                (d.issues.length
                    ? '<p><span class="cw-chip cw-chip--cancel">' + d.errors + ' ' + esc(L('errors')) + '</span> <span class="cw-chip cw-chip--wait">' + d.warnings + ' ' + esc(L('warnings')) + '</span> ' +
                      '<button type="button" class="cw-btn qs-recheck">' + esc(L('recheck')) + '</button></p><ul class="qs-issues">' + items + '</ul><p class="cw-meta">' + esc(L('checkSavedOnly')) + '</p>'
                    : '<p><span class="cw-chip cw-chip--done">' + esc(L('allGood')) + '</span> <button type="button" class="cw-btn qs-recheck">' + esc(L('recheck')) + '</button></p><p class="cw-meta">' + esc(L('checkedAt')) + ' ' + esc(this.checkedAtText()) + '</p>'));
        },
    });
});
