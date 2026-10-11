/**
 * Administration → Car wash → Assistant: provider (off / Anthropic), the API key (write-only:
 * the field only says "set"), models, limits, privacy level, retention, this month's usage and
 * cost, and a connection test. Values come from Assistant/action/adminGet and go back with
 * adminSave; the key is never sent to the browser.
 */
define(['custom:views/admin/settings-page', 'custom:lib/state'], function (Dep, State) {

    var esc = State.esc;

    return Dep.extend({

        scope: 'Assistant',

        api: 'Assistant/action/',

        events: Object.assign({}, Dep.prototype.events, {
            'click .ap-test': function () { this.test(); },
        }),

        groups: [
            {title: 'provider', fields: [
                {key: 'assistantProvider', type: 'select', options: ['off', 'anthropic']},
                {key: 'assistantApiKey', type: 'secret'},
            ]},
            {title: 'models', fields: [
                {key: 'assistantModel', type: 'text', placeholder: 'claude-sonnet-5-5'},
                {key: 'assistantFallbackModel', type: 'text', placeholder: 'claude-haiku-5-5'},
                {key: 'assistantEffort', type: 'select', options: ['low', 'medium', 'high', 'none']},
            ]},
            {title: 'limits', fields: [
                {key: 'assistantDailyPerUser', type: 'int'}, {key: 'assistantMonthlyQuestions', type: 'int'},
                {key: 'assistantMonthlyTokens', type: 'int'}, {key: 'assistantMaxSteps', type: 'int'},
                {key: 'assistantTurnSeconds', type: 'int'}, {key: 'assistantTurnTokens', type: 'int'},
                {key: 'assistantMaxOutputTokens', type: 'int'},
            ]},
            {title: 'privacy', fields: [
                {key: 'assistantPiiLevel', type: 'select', options: ['aggregate', 'names', 'contacts']},
                {key: 'assistantRetentionDays', type: 'int'}, {key: 'assistantUsdToEur', type: 'text'},
            ]},
        ],

        extraTop: function () {
            var d = this.data;
            var h = '<section class="cw-section"><div class="cw-chips"><span class="cw-chip cw-chip--' + (d.configured ? 'done' : 'pending') + '">' +
                esc(this.L(d.configured ? 'ready' : 'notReady')) + '</span></div>';

            if (!d.module) { h += '<p class="cw-meta">' + esc(this.L('moduleOffAdmin')) + '</p>'; }

            return h + '<p class="cw-meta">' + esc(this.L('legalHint')) + '</p><p class="cw-meta">' + esc(this.L('privacyHint')) + '</p></section>';
        },

        extraBottom: function () {
            return '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('usage', 'groups')) + '</h3><div class="ap-usage"></div></section>' +
                '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('test', 'groups')) + '</h3><p class="cw-meta">' + esc(this.L('testHint')) + '</p>' +
                '<button type="button" class="cw-btn ap-test">' + esc(this.L('testBtn')) + '</button> <span class="cw-meta ap-test-res" role="status"></span></section>';
        },

        afterPaint: function () { this.loadUsage(); },

        loadUsage: function () {
            var $u = this.$el.find('.ap-usage');

            Espo.Ajax.postRequest('Assistant/action/usage', {}).then(function (r) {
                if (!r.questions) { $u.html('<p class="cw-meta">' + esc(this.L('usageNone')) + '</p>'); return; }

                var rows = (r.byUser || []).map(function (x) {
                    return '<tr><td>' + esc(x.user) + '</td><td>' + esc(x.questions) + '</td><td>' + esc((x.costUsd * r.usdToEur).toFixed(4)) + ' €</td></tr>';
                }).join('');

                $u.html('<div class="cw-chips"><span class="cw-chip cw-chip--pending">' + esc(this.L('usageQuestions')) + ': ' + esc(r.questions) + '</span>' +
                    '<span class="cw-chip cw-chip--pending">' + esc(this.L('usageTokens')) + ': ' + esc(r.tokens) + '</span>' +
                    '<span class="cw-chip cw-chip--pending">' + esc(this.L('usageCost')) + ': ≈ ' + esc(r.costEur.toFixed(4)) + ' € (' + esc(r.costUsd.toFixed(4)) + ' $)</span></div>' +
                    (rows ? '<table class="table"><caption class="cw-meta">' + esc(this.L('usageByUser')) + '</caption><tbody>' + rows + '</tbody></table>' : ''));
            }.bind(this)).catch(function (xhr) { if (xhr) { xhr.errorIsHandled = true; } });
        },

        test: function () {
            var $r = this.$el.find('.ap-test-res');

            $r.text('…');
            Espo.Ajax.postRequest('Assistant/action/adminTest', {}, {timeout: 40000}).then(function (r) {
                $r.text(r.ok ? this.L('testOk') + ' (' + r.model + ', ' + r.ms + ' ms)' : this.L('testFail') + ': ' + (r.error === 'not_configured' ? this.L('notReady') : r.error));
                this.loadUsage();
            }.bind(this)).catch(function () { $r.text(this.L('testFail')); }.bind(this));
        },
    });
});
