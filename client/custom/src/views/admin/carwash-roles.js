/**
 * Administration -> Roles and access (#Admin/carwashRoles): the car wash's levels in plain words.
 * Everything comes from the server (Access/action/overview): the "can / cannot" lists are COMPUTED from the real Role rows,
 * so the page cannot disagree with the actual rights; a seeded role edited by hand is flagged. The page changes no right itself:
 * it creates users with a role, moves a user to another level (User.type / rolesIds through the stock API) and links to the
 * stock role editor for fine tuning.
 */
define('custom:views/admin/carwash-roles', ['view', 'custom:lib/state'], function (View, State) {

    var esc = State.esc;

    return View.extend({

        templateContent: '<div class="cw-acc"></div>',

        events: {
            'click .cw-acc__create': function (e) { this.createUser($(e.currentTarget).attr('data-kind'), $(e.currentTarget).attr('data-role')); },
            'click .cw-acc__change': function (e) { this.openChange($(e.currentTarget).attr('data-user')); },
            'click .cw-acc__cancel': function () { this.paint(); },
            'click .cw-acc__save': function (e) { this.saveChange($(e.currentTarget).attr('data-user')); },
            'click .cw-acc__seed': function () { this.seed(); },
        },

        L: function (key) { return this.translate(key, 'labels', 'CwRoles'); },

        updatePageTitle: function () { this.setPageTitle(this.L('title')); },

        afterRender: function () { this.load(); },

        load: function () {
            var $b = this.$el.find('.cw-acc');

            if (!this.data) { $b.html(State.loading(this.L('loading'))); }

            Espo.Ajax.postRequest('Access/action/overview', {}).then(function (d) {
                this.data = d;
                this.paint();
            }.bind(this)).catch(function () {
                $b.html(State.error({title: this.L('loadError'), retryLabel: this.L('retry')}));
                $b.find('.action-retry').on('click', this.load.bind(this));
            }.bind(this));
        },

        seed: function () {
            Espo.Ajax.postRequest('Access/action/seedRoles', {}).then(function () {
                Espo.Ui.success(this.L('seeded'));
                this.load();
            }.bind(this));
        },

        /** every level that has a role to put a user in, plus the owner (type admin) */
        targets: function () {
            var t = [{value: 'admin', label: this.L('level_owner')}];

            this.data.levels.forEach(function (l) {
                if (l.roleId) { t.push({value: l.roleId, label: this.levelTitle(l) + (l.roleName ? ' (' + l.roleName + ')' : '')}); }
            }, this);
            (this.data.otherRoles || []).forEach(function (l) { t.push({value: l.roleId, label: l.roleName}); });

            return t;
        },

        levelTitle: function (l) { return l.kind === 'other' ? l.roleName : this.L('level_' + l.kind); },

        capList: function (l, wanted) {
            var keys = this.data.capabilityKeys.filter(function (k) { return !!l.capabilities[k] === wanted; });

            if (!keys.length) { return '<li class="cw-meta">' + esc(this.L(wanted ? 'nothing' : 'everythingAllowed')) + '</li>'; }

            return keys.map(function (k) {
                return '<li><span class="fas ' + (wanted ? 'fa-check cw-acc__yes' : 'fa-xmark cw-acc__no') + '" aria-hidden="true"></span> ' + esc(this.L('cap_' + k)) + '</li>';
            }, this).join('');
        },

        usersHtml: function (l) {
            if (!l.users.length) { return '<p class="cw-meta">' + esc(this.L('noUsers')) + '</p>'; }

            return '<ul class="cw-acc__users">' + l.users.map(function (u) {
                return '<li class="cw-acc__user" data-user="' + esc(u.id) + '"><a href="#User/view/' + esc(u.id) + '">' + esc(u.name) + '</a> ' +
                    '<span class="cw-meta">' + esc(u.userName) + '</span> ' +
                    '<button type="button" class="cw-btn cw-acc__change" data-user="' + esc(u.id) + '">' + esc(this.L('changeRole')) + '</button></li>';
            }, this).join('') + '</ul>';
        },

        levelHtml: function (l) {
            var self = this, kind = l.kind, role = l.roleId;
            var h = '<section class="cw-section cw-acc__level" data-kind="' + esc(kind) + '">' +
                '<h4 class="cw-h2">' + esc(this.levelTitle(l)) + (l.roleName && kind !== 'other' ? ' <span class="cw-chip cw-chip--pending">' + esc(l.roleName) + '</span>' : '') + '</h4>';

            if (kind !== 'other') { h += '<p class="cw-acc__lead">' + esc(this.L('desc_' + kind)) + '</p>'; }
            if (kind === 'owner') { h += '<p class="cw-meta">' + esc(this.L('ownerNote')) + '</p>'; }

            if ((kind === 'manager' || kind === 'operator') && !role) {
                h += '<p class="cw-acc__warn">' + esc(this.L('noRole')) + ' <button type="button" class="cw-btn cw-acc__seed">' + esc(this.L('seedRoles')) + '</button></p>';
            }

            if (l.drift && l.drift.length) {
                h += '<p class="cw-acc__warn"><span class="fas fa-triangle-exclamation" aria-hidden="true"></span> ' + esc(this.L('drift')) + ' <span class="cw-meta">' + esc(l.drift.join(', ')) + '</span></p>';
            }

            h += '<div class="cw-acc__cols"><div><h5 class="cw-acc__colhead">' + esc(this.L('canHead')) + '</h5><ul class="cw-acc__caps">' + this.capList(l, true) + '</ul></div>' +
                '<div><h5 class="cw-acc__colhead">' + esc(this.L('cannotHead')) + '</h5><ul class="cw-acc__caps">' + this.capList(l, false) + '</ul></div></div>';

            h += '<h5 class="cw-acc__colhead">' + esc(this.L('usersHead')) + ' (' + l.users.length + ')</h5>' + this.usersHtml(l);

            h += '<p class="cw-acc__actions">';
            if (kind === 'owner' || role) {
                h += '<button type="button" class="cw-btn cw-btn--primary cw-acc__create" data-kind="' + esc(kind) + '" data-role="' + esc(role || '') + '">' +
                    esc(this.L('createUser') + ' ' + (kind === 'owner' ? this.L('level_owner') : '«' + (l.roleName || this.levelTitle(l)) + '»')) + '</button> ';
            }
            h += role ? '<a class="cw-btn" href="#Role/view/' + esc(role) + '">' + esc(this.L('editRole')) + '</a>' : '';
            h += '</p></section>';

            return h;
        },

        workersHtml: function () {
            var w = this.data.workers, tl = w.tablets || [];
            var tablets = tl.length ? '<strong>' + tl.length + '</strong> (' + tl.slice(0, 4).map(function (t) { return esc(t.name); }).join(', ') + (tl.length > 4 ? ', …' : '') + ')' : esc(this.L('noTablets'));
            var list = function (arr, ok, self) {
                return arr.map(function (k) { return '<li><span class="fas ' + (ok ? 'fa-check cw-acc__yes' : 'fa-xmark cw-acc__no') + '" aria-hidden="true"></span> ' + esc(self.L(k)) + '</li>'; }).join('');
            };

            return '<section class="cw-section cw-acc__level" data-kind="workers"><h4 class="cw-h2">' + esc(this.L('level_workers')) + '</h4>' +
                '<p class="cw-acc__lead">' + esc(this.L('desc_workers')) + '</p>' +
                '<div class="cw-acc__cols"><div><h5 class="cw-acc__colhead">' + esc(this.L('canHead')) + '</h5><ul class="cw-acc__caps">' +
                list(['w_can1', 'w_can2', 'w_can3', 'w_can4'], true, this) + '</ul></div><div><h5 class="cw-acc__colhead">' + esc(this.L('cannotHead')) + '</h5><ul class="cw-acc__caps">' +
                list(['w_no1', 'w_no2', 'w_no3'], false, this) + '</ul></div></div>' +
                '<p class="cw-meta">' + esc(this.L('workersCount')) + ': <strong>' + w.hired + '</strong>, ' + esc(this.L('withPin')) + ': <strong>' + w.withPin + '</strong>. ' +
                esc(this.L('tablets')) + ': ' + tablets + '.</p>' +
                '<p class="cw-acc__actions"><a class="cw-btn cw-btn--primary" href="#Admin/kiosk">' + esc(this.L('openTablets')) + '</a> <a class="cw-btn" href="#Employee">' + esc(this.L('openEmployees')) + '</a></p></section>';
        },

        paint: function () {
            var d = this.data, h = '';

            h += '<div class="cw-adm__head"><h3 class="cw-adm__title">' + esc(this.L('title')) + '</h3><p class="cw-meta cw-adm__lead">' + esc(this.L('lead')) + '</p></div>';
            h += '<div class="cw-acc__grid">';
            d.levels.forEach(function (l) { h += this.levelHtml(l); }, this);
            h += this.workersHtml();
            (d.otherRoles || []).forEach(function (l) { h += this.levelHtml(l); }, this);
            h += '</div>';

            if (d.usersWithoutRole && d.usersWithoutRole.length) {
                h += '<section class="cw-section"><h4 class="cw-h2">' + esc(this.L('noRoleUsers')) + '</h4><p class="cw-meta">' + esc(this.L('noRoleUsersHint')) + '</p>' +
                    this.usersHtml({users: d.usersWithoutRole}) + '</section>';
            }

            h += '<p class="cw-meta">' + esc(this.L('footer')) + ' <a href="#Admin/roles">' + esc(this.L('standardRoles')) + '</a>.</p>';

            this.$el.find('.cw-acc').html(h);
        },

        createUser: function (kind, roleId) {
            var attrs = {type: kind === 'owner' ? 'admin' : 'regular'};

            if (roleId) {
                var names = {};

                names[roleId] = (this.data.levels.concat(this.data.otherRoles || []).filter(function (l) { return l.roleId === roleId; })[0] || {}).roleName || '';
                attrs.rolesIds = [roleId];
                attrs.rolesNames = names;
            }

            this.createView('createUser', 'views/modals/edit', {scope: 'User', attributes: attrs, fullFormDisabled: false}, function (view) {
                view.render();
                this.listenToOnce(view, 'after:save', function () { this.clearView('createUser'); this.load(); }.bind(this));
            }.bind(this));
        },

        openChange: function (userId) {
            var $li = this.$el.find('.cw-acc__user[data-user="' + userId + '"]');
            var sel = '<select class="cw-field cw-acc__select" aria-label="' + esc(this.L('changeRole')) + '">' +
                this.targets().map(function (t) { return '<option value="' + esc(t.value) + '">' + esc(t.label) + '</option>'; }).join('') + '</select>';

            $li.find('.cw-acc__change').replaceWith(sel + ' <button type="button" class="cw-btn cw-btn--primary cw-acc__save" data-user="' + esc(userId) + '">' + esc(this.L('save')) +
                '</button> <button type="button" class="cw-btn cw-acc__cancel">' + esc(this.L('cancel')) + '</button>');
        },

        saveChange: function (userId) {
            var v = this.$el.find('.cw-acc__user[data-user="' + userId + '"] .cw-acc__select').val();

            if (userId === this.getUser().id && v !== 'admin') {
                Espo.Ui.error(this.L('notSelf'));

                return;
            }

            var body = v === 'admin' ? {type: 'admin', rolesIds: []} : {type: 'regular', rolesIds: [v]};

            Espo.Ajax.putRequest('User/' + userId, body).then(function () {
                Espo.Ui.success(this.L('changed'));
                this.load();
            }.bind(this));
        },
    });
});
