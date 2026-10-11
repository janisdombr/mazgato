/**
 * Administration → Car wash → Site content. A raw-HTML editor for the landing pages: pick a page,
 * edit the whole HTML, Save overwrites it. Backed by CarWash/action/getSitePage|saveSitePage,
 * which write into the persistent landing volume — so edits are live at once and a code deploy
 * never reverts them.
 */
define(['view'], function (View) {

    return View.extend({

        templateContent:
            '<div class="page-header">' +
                '<h3><a href="#Admin" class="sc-back"></a> <span class="chevron-right"></span> <span class="sc-title"></span></h3>' +
            '</div>' +
            '<div style="margin-top:12px;">' +
                '<div style="margin-bottom:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">' +
                    '<select class="form-control sc-page" style="max-width:300px;"></select>' +
                    '<button class="btn btn-primary sc-save"></button>' +
                    '<select class="form-control sc-versions" style="max-width:260px;"></select>' +
                    '<span class="sc-status text-muted small"></span>' +
                '</div>' +
                '<textarea class="form-control sc-html" spellcheck="false" ' +
                    'style="width:100%; height:62vh; font-family:monospace; font-size:12px; white-space:pre; overflow:auto;"></textarea>' +
                '<div class="text-muted small sc-hint" style="margin-top:6px;"></div>' +
            '</div>',

        pageLabels: {
            'index.html': 'Sākumlapa (LV)',
            'ru/index.html': 'Главная (RU)',
            'en/index.html': 'Home (EN)',
            'privatuma-politika.html': 'Privātuma politika (LV)',
            'ru/privatuma-politika.html': 'Политика конфиденциальности (RU)',
            'en/privatuma-politika.html': 'Privacy policy (EN)',
        },

        setup: function () {
            this.dirty = false;
        },

        afterRender: function () {
            this.$el.find('.sc-back').text(this.translate('Administration', 'labels', 'Admin'));
            this.$el.find('.sc-title').text(this.translate('siteContent', 'labels', 'Admin'));
            this.$el.find('.sc-save').text(this.translate('Save', 'labels', 'Global'));
            this.$el.find('.sc-hint').text(this.translate('siteContentHint', 'labels', 'Admin'));

            this.$page = this.$el.find('.sc-page');
            this.$html = this.$el.find('.sc-html');
            this.$status = this.$el.find('.sc-status');
            this.$versions = this.$el.find('.sc-versions');

            this.$page.on('change', this.loadPage.bind(this));
            this.$html.on('input', function () { this.dirty = true; this.$status.text(''); }.bind(this));
            this.$el.find('.sc-save').on('click', this.save.bind(this));
            this.$versions.on('change', this.pickVersion.bind(this));

            this.loadList();
        },

        loadVersions: function () {
            var page = this.$page.val();

            Espo.Ajax.postRequest('CarWash/action/listSiteVersions', {page: page}).then(function (res) {
                res = res || {};
                var versions = res.versions || [];

                this.$versions.empty();
                this.$versions.append($('<option>').val('').text(
                    this.translate('siteVersions', 'labels', 'Admin') +
                    (versions.length ? ' (' + versions.length + ')' : ' — 0')
                ));
                versions.forEach(function (v) {
                    this.$versions.append($('<option>').val(v.version).text(v.label));
                }.bind(this));
            }.bind(this));
        },

        pickVersion: function () {
            var version = this.$versions.val();

            if (!version) { return; }

            Espo.Ajax.postRequest('CarWash/action/getSiteVersion', {
                page: this.$page.val(),
                version: version,
            }).then(function (res) {
                res = res || {};
                this.$html.val(res.html || '');
                this.dirty = true;
                this.$status.text(this.translate('siteVersionLoaded', 'labels', 'Admin'));
            }.bind(this));
        },

        loadList: function () {
            Espo.Ajax.postRequest('CarWash/action/getSitePage', {page: 'index.html'})
                .then(function (res) {
                    res = res || {};
                    var pages = res.pages || ['index.html'];

                    this.$page.empty();
                    pages.forEach(function (p) {
                        this.$page.append($('<option>').val(p).text(this.pageLabels[p] || p));
                    }.bind(this));
                    this.$page.val('index.html');

                    this.setContent(res);
                }.bind(this));
        },

        loadPage: function () {
            Espo.Ajax.postRequest('CarWash/action/getSitePage', {page: this.$page.val()})
                .then(this.setContent.bind(this));
        },

        setContent: function (res) {
            res = res || {};
            this.$html.val(res.html || '');
            this.dirty = false;
            this.$status.text(res.exists === false ? this.translate('siteContentMissing', 'labels', 'Admin') : '');
            this.loadVersions();
        },

        save: function () {
            Espo.Ui.notify(' ... ');

            Espo.Ajax.postRequest('CarWash/action/saveSitePage', {
                page: this.$page.val(),
                html: this.$html.val(),
            }).then(function (res) {
                res = res || {};
                this.dirty = false;
                Espo.Ui.success(this.translate('Saved', 'labels', 'Global'));
                this.$status.text(this.translate('Saved', 'labels', 'Global') + ' (' + (res.bytes || 0) + ' B)');
                this.loadVersions();
            }.bind(this)).catch(function () {
                Espo.Ui.error(this.translate('error', 'messages'));
            }.bind(this));
        },
    });
});
