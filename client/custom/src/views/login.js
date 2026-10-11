/*
 * Sign-in screen. Extends the stock view (so every auth method, 2FA and the AGPL footer keep working) and only
 * brands it: the name shown under the logo is DATA - Settings.applicationName (public before login), never a
 * string in code. The stock "(c) EspoCRM, Inc." footer line and the source-code link are left untouched
 * (docs/AGPL.md). Styling is in cw-core.css (#login / .cw-login__*), tokens only.
 */
define('custom:views/login', ['views/login'], function (Dep) {

    return Dep.extend({

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            var name = (this.getConfig().get('productName') || this.getConfig().get('applicationName') || '').toString().trim();
            var $head = this.$el.find('#login .panel-heading');

            if (name && !$head.find('.cw-login__name').length) {
                $head.append($('<div class="cw-login__name"></div>').text(name));
            }

            this.$el.find('#login').addClass('cw-login');

            if (!this.$el.find('#field-userName').val()) {
                this.$el.find('#field-userName').focus();
            }
        }
    });
});
