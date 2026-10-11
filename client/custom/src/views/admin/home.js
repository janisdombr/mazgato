/**
 * Administration start page (#Admin) for a car wash: only what a wash needs, grouped by TASK, each item with an icon and a
 * one-line description, a search box, and the whole stock EspoCRM settings list folded under "Show all standard settings"
 * (nothing is removed - the stock pages keep working, they are just not the first thing the owner sees).
 *
 * The groups are data: metadata `app.cwAdminHome` (ids/urls/icons); texts are i18n scope CwAdminHome
 * (`group_<id>`, `item_<id>`, `desc_<id>`, `info_<id>`). Opened by custom:controllers/admin.
 */
define('custom:views/admin/home', ['view', 'custom:lib/state'], function (View, State) {

    var esc = State.esc;

    function norm(s) {
        s = String(s || '').toLowerCase();

        return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
    }

    return View.extend({

        templateContent: '<div class="cw-adm"></div>',

        events: {
            'input .cw-adm__search': function (e) { this.query = e.currentTarget.value; this.filter(); },
            'click .cw-adm__toggle': function () { this.toggleStd(); },
            'click [data-action="clearCache"]': function (e) { e.preventDefault(); this.trigger('clear-cache'); },
            'click [data-action="rebuild"]': function (e) { e.preventDefault(); this.trigger('rebuild'); },
        },

        L: function (key) { return this.translate(key, 'labels', 'CwAdminHome'); },

        setup: function () {
            this.query = '';
            this.stdOpen = false;
        },

        updatePageTitle: function () { this.setPageTitle(this.translate('Administration', 'labels', 'Admin')); },

        groups: function () {
            var meta = this.getMetadata(), list = (meta.get(['app', 'cwAdminHome', 'groups']) || []);

            return list.map(function (g) {
                return {
                    id: g.id, icon: g.icon, title: this.L('group_' + g.id),
                    items: (g.items || []).filter(function (it) {
                        var m = /^#([A-Za-z]+)$/.exec(it.url || '');

                        return !(m && meta.get(['scopes', m[1], 'disabled']));
                    }).map(function (it) {
                        return {
                            id: it.id, url: it.url, icon: it.icon, info: !!it.info,
                            label: this.L('item_' + it.id), desc: this.L('desc_' + it.id),
                            infoText: it.info ? this.L('info_' + it.id) : '',
                        };
                    }, this),
                };
            }, this);
        },

        /** the stock panels (every one except our own "carwash") as plain rows */
        stdPanels: function () {
            var panels = this.getMetadata().get('app.adminPanel') || {}, out = [], lang = this.getLanguage();

            Object.keys(panels).forEach(function (name) {
                if (name === 'carwash') { return; }

                var p = panels[name], items = [];

                (p.itemList || []).concat(p.items || []).forEach(function (it) {
                    var label = it.labelTranslation ? lang.translatePath(it.labelTranslation) : this.translate(it.label, 'labels', 'Admin');
                    var desc = it.description ? this.translate(it.description, 'descriptions', 'Admin') : '';
                    var kw = it.description ? (lang.get('Admin', 'keywords', it.description) || '') : '';

                    items.push({label: label, desc: desc === it.description ? '' : desc, url: it.url, action: it.action, icon: it.iconClass, kw: kw});
                }, this);

                out.push({
                    title: p.labelTranslation ? lang.translatePath(p.labelTranslation) : this.translate(p.label, 'labels', 'Admin'),
                    order: 'order' in p ? p.order : 9999, items: items,
                });
            }, this);

            out.sort(function (a, b) { return a.order - b.order; });

            return out;
        },

        afterRender: function () {
            var h = '';

            h += '<div class="cw-adm__head"><h3 class="cw-adm__title">' + esc(this.translate('Administration', 'labels', 'Admin')) + '</h3>' +
                '<p class="cw-meta cw-adm__lead">' + esc(this.L('lead')) + '</p>' +
                '<input type="search" class="cw-field cw-adm__search" maxlength="64" spellcheck="false" autocomplete="off" placeholder="' +
                esc(this.L('searchPlaceholder')) + '" aria-label="' + esc(this.L('searchPlaceholder')) + '" value="' + esc(this.query) + '"></div>';

            h += '<div class="cw-adm__groups">';
            this.groups().forEach(function (g) {
                h += '<section class="cw-section cw-adm__group" data-group="' + esc(g.id) + '"><h4 class="cw-h2"><span class="' + esc(g.icon) +
                    ' cw-adm__gicon" aria-hidden="true"></span> ' + esc(g.title) + '</h4><ul class="cw-adm__items">';
                g.items.forEach(function (it) {
                    h += '<li class="cw-adm__item" data-find="' + esc(norm(it.label + ' ' + it.desc + ' ' + g.title)) + '">' +
                        (it.info ? '<details class="cw-adm__info"><summary class="cw-adm__link"><span class="' + esc(it.icon) + '" aria-hidden="true"></span> ' + esc(it.label) +
                            '</summary><div class="cw-meta cw-adm__infotext">' + esc(it.infoText).replace(/\n/g, '<br>') + '</div></details>'
                            : '<a class="cw-adm__link" href="' + esc(it.url) + '"><span class="' + esc(it.icon) + '" aria-hidden="true"></span> ' + esc(it.label) + '</a>') +
                        '<span class="cw-meta cw-adm__desc">' + esc(it.desc) + '</span></li>';
                });
                h += '</ul></section>';
            });
            h += '</div><p class="cw-meta cw-adm__none" hidden>' + esc(this.L('nothingFound')) + '</p>';

            h += '<section class="cw-section cw-adm__stdbox"><button type="button" class="cw-btn cw-adm__toggle" aria-expanded="false">' +
                '<span class="fas fa-chevron-right cw-adm__chev" aria-hidden="true"></span> <span class="cw-adm__togglelabel">' + esc(this.L('showStandard')) + '</span></button>' +
                '<p class="cw-meta">' + esc(this.L('standardHint')) + '</p><div class="cw-adm__std" hidden>';
            this.stdPanels().forEach(function (p) {
                h += '<div class="cw-adm__stdpanel"><h5 class="cw-adm__stdtitle">' + esc(p.title) + '</h5><ul class="cw-adm__items">';
                p.items.forEach(function (it) {
                    var open = it.url ? '<a class="cw-adm__link" href="' + esc(it.url) + '">' : '<a class="cw-adm__link" href="#" role="button" data-action="' + esc(it.action || '') + '">';

                    h += '<li class="cw-adm__item" data-find="' + esc(norm(it.label + ' ' + it.desc + ' ' + it.kw)) + '">' + open +
                        (it.icon ? '<span class="' + esc(it.icon) + '" aria-hidden="true"></span> ' : '') + esc(it.label) + '</a>' +
                        (it.desc ? '<span class="cw-meta cw-adm__desc">' + esc(it.desc) + '</span>' : '') + '</li>';
                });
                h += '</ul></div>';
            });
            h += '</div></section>';

            this.$el.find('.cw-adm').html(h);
            this.filter();
        },

        toggleStd: function (force) {
            this.stdOpen = typeof force === 'boolean' ? force : !this.stdOpen;
            this.$el.find('.cw-adm__std').prop('hidden', !this.stdOpen);
            this.$el.find('.cw-adm__toggle').attr('aria-expanded', this.stdOpen ? 'true' : 'false');
            this.$el.find('.cw-adm__chev').toggleClass('fa-chevron-right', !this.stdOpen).toggleClass('fa-chevron-down', this.stdOpen);
            this.$el.find('.cw-adm__togglelabel').text(this.L(this.stdOpen ? 'hideStandard' : 'showStandard'));
        },

        /** show only items matching every word of the query; the standard list opens by itself when only it has matches */
        filter: function () {
            var words = norm(this.query).split(/\s+/).filter(Boolean), $root = this.$el, own = 0, std = 0;

            function match($li) {
                var f = $li.attr('data-find') || '';

                return words.every(function (w) { return f.indexOf(w) !== -1; });
            }

            $root.find('.cw-adm__group').each(function () {
                var $g = $(this), n = 0;

                $g.find('.cw-adm__item').each(function () {
                    var ok = !words.length || match($(this));

                    $(this).prop('hidden', !ok);
                    if (ok) { n++; }
                });
                $g.prop('hidden', n === 0);
                own += n;
            });

            $root.find('.cw-adm__stdpanel').each(function () {
                var $p = $(this), n = 0;

                $p.find('.cw-adm__item').each(function () {
                    var ok = !words.length || match($(this));

                    $(this).prop('hidden', !ok);
                    if (ok) { n++; }
                });
                $p.prop('hidden', n === 0);
                std += n;
            });

            if (words.length) {
                this.toggleStd(own === 0 && std > 0);
            }

            $root.find('.cw-adm__none').prop('hidden', !(words.length && own === 0 && std === 0));
        },
    });
});
