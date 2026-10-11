/**
 * Administration → Car wash → Notifications: which channels carry the "car ready" / booking
 * messages, their credentials (secrets are write-only), the review link, and a test send.
 * The test goes through the same adapter a real message uses.
 */
define(['custom:views/admin/settings-page', 'custom:lib/state'], function (Dep, State) {

    var esc = State.esc;
    var CH = ['Email', 'WhatsApp', 'Sms', 'Telegram'];

    return Dep.extend({

        scope: 'Notify',

        events: Object.assign({}, Dep.prototype.events, {
            'click .ap-test': function (e) { this.test($(e.currentTarget).data('ch')); },
        }),

        groups: [
            {title: 'general', fields: [
                {key: 'reviewUrl', type: 'text', placeholder: 'https://'},
                {key: 'notifyChannelOrder', type: 'text', placeholder: 'Telegram,Email,WhatsApp,Sms'},
                {key: 'notifyCountryCode', type: 'text', placeholder: '371'},
            ]},
            {title: 'email', fields: [{key: 'notifyEmailOn', type: 'bool'}]},
            {title: 'whatsapp', fields: [
                {key: 'notifyWhatsappOn', type: 'bool'}, {key: 'whatsappPhoneNumberId', type: 'text'},
                {key: 'whatsappAccessToken', type: 'secret'}, {key: 'whatsappNotifyTemplate', type: 'text'},
                {key: 'whatsappTemplateLang', type: 'text', placeholder: 'lv'},
            ]},
            {title: 'sms', fields: [
                {key: 'notifySmsOn', type: 'bool'}, {key: 'smsWebhookUrl', type: 'text', placeholder: 'https://'},
                {key: 'smsWebhookMethod', type: 'select', options: ['POST', 'PUT']}, {key: 'smsWebhookFormat', type: 'select', options: ['json', 'form']},
                {key: 'smsWebhookBody', type: 'area'}, {key: 'smsWebhookHeaders', type: 'secret'},
                {key: 'smsBasicAuth', type: 'secret'}, {key: 'smsFrom', type: 'text'},
            ]},
            {title: 'telegram', fields: [
                {key: 'notifyTelegramOn', type: 'bool'}, {key: 'telegramBotUsername', type: 'text'},
                {key: 'telegramBotToken', type: 'secret'}, {key: 'telegramWebhookSecret', type: 'secret'},
            ]},
            {title: 'inbound', fields: [{key: 'notifyInboundSecret', type: 'secret'}]},
        ],

        chName: function (c) {
            return this.getLanguage().translateOption(c, 'channel', 'OrderMessage') || c;
        },

        extraTop: function () {
            var d = this.data;
            var chips = CH.map(function (c) {
                return '<span class="cw-chip cw-chip--' + (d.channels[c] ? 'done' : 'pending') + '">' + esc(this.chName(c)) + ' · ' +
                    esc(this.L(d.channels[c] ? 'ready' : 'notReady')) + '</span>';
            }, this).join(' ');
            var h = '<section class="cw-section"><div class="cw-chips">' + chips + '</div>';

            if (!d.modules.notify) { h += '<p class="cw-meta">' + esc(this.L('moduleOff')) + '</p>'; }

            return h + '</section>';
        },

        extraBottom: function () {
            return '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('test', 'groups')) + '</h3><p class="cw-meta">' + esc(this.L('testHint')) + '</p>' +
                CH.map(function (c) {
                    return '<div class="cw-fields"><div class="cw-field"><label>' + esc(this.chName(c)) + '</label>' +
                        '<input class="form-control ap-to" data-ch="' + c + '" placeholder="' + esc(c === 'Email' ? 'name@example.com' : (c === 'Telegram' ? 'chat id' : '+371…')) + '">' +
                        '<button type="button" class="cw-btn ap-test" data-ch="' + c + '">' + esc(this.L('send')) + '</button>' +
                        '<div class="cw-meta ap-res" data-ch="' + c + '"></div></div></div>';
                }, this).join('') + '</section>';
        },

        test: function (ch) {
            var to = this.$el.find('.ap-to[data-ch="' + ch + '"]').val();
            var $r = this.$el.find('.ap-res[data-ch="' + ch + '"]');

            $r.text('…');
            Espo.Ajax.postRequest('Booking/action/notifyTest', {channel: ch, to: to}).then(function (r) {
                $r.text(r.ok ? this.L('testOk') : this.L('testFail') + ': ' + r.error);
            }.bind(this)).catch(function () { $r.text(this.L('testFail')); }.bind(this));
        },
    });
});
