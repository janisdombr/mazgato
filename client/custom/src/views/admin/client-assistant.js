/**
 * Administration → Car wash → Customer chat (AI): the public chat on /booking/ and /booking/chat/.
 * On/off, provider and the (shared, write-only) API key, what the chat knows (address, phone,
 * payments, FAQ - hours and prices come from Online booking and the service list), per-visitor and
 * overall limits, the monthly cost ceiling, retention, the knowledge preview, usage and a
 * connection test. Values come from ClientAssistant/action/adminGet and go back with adminSave.
 */
define(['custom:views/admin/settings-page', 'custom:lib/state'], function (Dep, State) {

    var esc = State.esc;

    return Dep.extend({

        scope: 'ClientAssistant',

        api: 'ClientAssistant/action/',

        events: Object.assign({}, Dep.prototype.events, {
            'click .ap-test': function () { this.test(); },
            'click .ap-reset-rate': function () {
                Espo.Ajax.postRequest('ClientAssistant/action/adminResetRate', {}).then(function () { Espo.Ui.success(this.L('rateReset')); }.bind(this));
            },
        }),

        groups: [
            {title: 'switch', fields: [{key: 'clientAssistantEnabled', type: 'bool'}]},
            {title: 'provider', fields: [
                {key: 'clientAssistantProvider', type: 'select', options: ['off', 'anthropic', 'openai', 'gemini', 'mistral', 'deepseek', 'groq']},
                {key: 'clientAssistantApiKey', type: 'secret'},
                {key: 'clientAssistantModel', type: 'text', placeholder: 'claude-haiku-5-5'},
                {key: 'clientAssistantFallbackModel', type: 'text', placeholder: 'claude-haiku-5-5'},
            ]},
            {title: 'knowledge', fields: [
                {key: 'clientAssistantAddress', type: 'text'}, {key: 'clientAssistantPhone', type: 'text'},
                {key: 'clientAssistantEmail', type: 'text'}, {key: 'clientAssistantPayments', type: 'area'},
                {key: 'clientAssistantFaq', type: 'area'},
            ]},
            {title: 'limits', fields: [
                {key: 'clientAssistantPerIpMinute', type: 'int'}, {key: 'clientAssistantPerIpHour', type: 'int'},
                {key: 'clientAssistantPerIpDay', type: 'int'}, {key: 'clientAssistantBookingsPerIpDay', type: 'int'},
                {key: 'clientAssistantDayQuestions', type: 'int'}, {key: 'clientAssistantMonthQuestions', type: 'int'},
                {key: 'clientAssistantMonthTokens', type: 'int'}, {key: 'clientAssistantMonthBudgetEur', type: 'text'},
            ]},
            {title: 'safety', fields: [
                {key: 'clientAssistantMaxSteps', type: 'int'}, {key: 'clientAssistantTurnSeconds', type: 'int'},
                {key: 'clientAssistantTurnTokens', type: 'int'}, {key: 'clientAssistantMaxOutputTokens', type: 'int'},
            ]},
            {title: 'privacy', fields: [{key: 'clientAssistantRetentionDays', type: 'int'}]},
        ],

        extraTop: function () {
            var d = this.data;
            var h = '<section class="cw-section"><div class="cw-chips"><span class="cw-chip cw-chip--' + (d.module && d.configured ? 'done' : 'pending') + '">' +
                esc(this.L(d.module && d.configured ? 'ready' : 'notReady')) + '</span></div>';

            if (!d.module) { h += '<p class="cw-meta">' + esc(this.L('moduleOffAdmin')) + '</p>'; }
            if (!d.bookingModule) { h += '<p class="cw-meta">' + esc(this.L('bookingOffHint')) + '</p>'; }

            h += '<p><strong>' + esc(this.L('pageLink')) + '</strong> <a href="' + esc(d.pageUrl) + '" target="_blank" rel="noopener">' + esc(d.pageUrl) + '</a></p>' +
                '<p><strong>' + esc(this.L('bookingLink')) + '</strong> <a href="' + esc(d.bookingUrl) + '" target="_blank" rel="noopener">' + esc(d.bookingUrl) + '</a></p>' +
                '<p class="cw-meta">' + esc(this.L('keyHint')) + '</p><p class="cw-meta">' + esc(this.L('legalHint')) + '</p><p class="cw-meta">' + esc(this.L('privacyHint')) + '</p></section>';

            return h;
        },

        extraBottom: function () {
            return '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('usage', 'groups')) + '</h3><div class="ap-usage"></div>' +
                '<button type="button" class="cw-btn ap-reset-rate">' + esc(this.L('resetRate')) + '</button></section>' +
                '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('test', 'groups')) + '</h3><p class="cw-meta">' + esc(this.L('testHint')) + '</p>' +
                '<button type="button" class="cw-btn ap-test">' + esc(this.L('testBtn')) + '</button> <span class="cw-meta ap-test-res" role="status"></span></section>' +
                '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('preview', 'groups')) + '</h3><p class="cw-meta">' + esc(this.L('knowledgeNote')) + '</p>' +
                '<textarea readonly rows="16" class="form-control ap-knowledge" aria-label="' + esc(this.L('preview', 'groups')) + '">' + esc(this.data.knowledge || '') + '</textarea></section>';
        },

        afterPaint: function () {
            this.loadUsage();
            this.$el.find('#ap-clientAssistantProvider').on('change', this.providerInfo.bind(this));
            this.providerInfo();
        },

        /**
         * Under the provider select: where the key will be sent (the fixed base URL from the server's catalogue), the
         * provider's data note, and the provider's default model as the placeholder of both model fields.
         */
        providerInfo: function () {
            var id = this.$el.find('#ap-clientAssistantProvider').val();
            var p = (this.data.providers || []).filter(function (x) { return x.id === id; })[0];
            var $box = this.$el.find('.ap-provider-info');

            if (!$box.length) {
                $box = $('<div class="cw-meta ap-provider-info" role="status"></div>');
                this.$el.find('#ap-clientAssistantProvider').closest('.cw-field').append($box);
            }

            if (!p) {
                $box.text('');
                return;
            }

            $box.text(this.L('providerSendsTo') + ' ' + p.base + ' - ' + this.L('providerNote_' + p.id) + ' ' + this.L('providerModels') + ' ' + p.models.join(', '));
            this.$el.find('#ap-clientAssistantModel, #ap-clientAssistantFallbackModel').attr('placeholder', p.model);
        },

        loadUsage: function () {
            var $u = this.$el.find('.ap-usage');

            Espo.Ajax.postRequest('ClientAssistant/action/usage', {}).then(function (r) {
                $u.html('<div class="cw-chips"><span class="cw-chip cw-chip--pending">' + esc(this.L('usageQuestions')) + ': ' + esc(r.questions) + ' (' + esc(this.L('usageToday')) + ': ' + esc(r.today) + ')</span>' +
                    '<span class="cw-chip cw-chip--pending">' + esc(this.L('usageBookings')) + ': ' + esc(r.bookings) + '</span>' +
                    '<span class="cw-chip cw-chip--pending">' + esc(this.L('usageTokens')) + ': ' + esc(r.tokens) + '</span>' +
                    '<span class="cw-chip cw-chip--pending">' + esc(this.L('usageCost')) + ': ≈ ' + esc(r.costEur.toFixed(4)) + ' € / ' + esc(this.L('usageBudget')) + ' ' + esc(r.limits.monthBudgetEur) + ' €</span></div>');
            }.bind(this)).catch(function (xhr) { if (xhr) { xhr.errorIsHandled = true; } });
        },

        test: function () {
            var $r = this.$el.find('.ap-test-res');

            $r.text('…');
            Espo.Ajax.postRequest('ClientAssistant/action/adminTest', {}, {timeout: 40000}).then(function (r) {
                $r.text(r.ok ? this.L('testOk') + ' (' + r.model + ', ' + r.ms + ' ms)' : this.L('testFail') + ': ' + (r.error === 'not_configured' ? this.L('notReady') : r.error));
                this.loadUsage();
            }.bind(this)).catch(function () { $r.text(this.L('testFail')); }.bind(this));
        },
    });
});
