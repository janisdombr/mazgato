/**
 * "Setup check" badge: how many gaps the Quick start check (QuickStart/action/check) finds in the catalogue, with a link to the wizard.
 * Administrators only (the endpoint answers 403 to anyone else - then the dashlet stays empty). Nothing is written.
 */
define(['custom:views/dashlets/base'], function (Dep) {

    return Dep.extend({

        name: 'QuickStart',

        autoFit: true,

        templateContent: '<div class="cw-pad"><div class="qs-badge" data-cw-fit></div></div>',

        afterRender: function () { this.load(); },

        actionRefresh: function () { this.load(); },

        t: function (n) { return this.translate(n, 'labels', 'QuickStart'); },

        load: function () {
            var $b = this.$el.find('.qs-badge');

            Espo.Ajax.postRequest('QuickStart/action/check', {}).then(function (d) {
                var n = d.errors + d.warnings, e = this.cwState.esc;

                $b.html(n
                    ? '<a href="#Admin/quickStart" class="cw-btn cw-btn--primary"><i class="fas fa-rocket"></i> ' + e(this.t('badge').replace('{n}', n)) + '</a> ' +
                      '<span class="cw-meta">' + d.errors + ' ' + e(this.t('errors')) + ', ' + d.warnings + ' ' + e(this.t('warnings')) + '</span>'
                    : '<span class="cw-chip cw-chip--done">' + e(this.t('badgeOk')) + '</span>');
                this.fitToContent();
            }.bind(this), function (xhr) {
                if (xhr) { xhr.errorIsHandled = true; }

                $b.html('');
            });
        },
    });
});
