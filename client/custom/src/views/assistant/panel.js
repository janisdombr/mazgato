/**
 * The assistant's chat. One body for the dashlet and for the full page (tablet), so both show the
 * same states and behave the same.
 *
 * - Talks only to Assistant/action/{status,ask,history,deleteHistory}.
 * - Model text is rendered ONLY through lib/assistant.renderMarkdown (escape first, small subset
 *   after); everything else is escaped with State.esc. Nothing from the model is injected as raw
 *   markup, and no links are ever built.
 * - A draft ("Open draft") opens the ordinary create form with the attributes pre-filled; the
 *   assistant itself saves nothing.
 * - States (lib/state): loading, not configured, module off, no access, limit reached, busy,
 *   session expired, error with retry.
 */
define(['view', 'custom:lib/state', 'custom:lib/assistant'], function (Dep, State, A) {

    var esc = State.esc;

    return Dep.extend({

        templateContent: '<div class="cw-as cw-pad"></div>',

        events: {
            'submit .as-form': function (e) { e.preventDefault(); this.submit(); },
            'keydown .as-input': function (e) {
                if (e.key === 'Enter' && !e.shiftKey && !e.originalEvent.isComposing) { e.preventDefault(); this.submit(); }
            },
            'click .as-chip': function (e) { this.ask(String($(e.currentTarget).data('q'))); },
            'click .as-draft': function (e) { this.openDraft(parseInt($(e.currentTarget).data('i'), 10), $(e.currentTarget).data('m')); },
            'click .as-history': function () { this.toggleHistory(); },
            'click .as-clear': function () { this.clearHistory(); },
            'click .as-admin': function () { this.getRouter().navigate('#Admin/assistant', {trigger: true}); },
            'click .as-retry': function () { this.loadStatus(); },
            'click .as-relogin': function () { window.location.reload(); },
        },

        setup: function () {
            this.mode = this.options.mode || 'dashlet';
            this.fromPage = this.options.fromPage || '';
            this.status = null;
            this.drafts = {};
            this.draftSeq = 0;
            this.busy = false;
        },

        t: function (key, vars) { return A.fill(this.translate(key, 'labels', 'Assistant'), vars); },

        afterRender: function () {
            this.$root = this.$el.find('.cw-as').addClass('cw-as--' + this.mode);
            this.$root.html(
                '<div class="as-state"></div>' +
                '<div class="as-log cw-as__log" role="log" aria-live="polite"></div>' +
                '<div class="as-chips cw-as__chips" role="group" aria-label="' + esc(this.t('try')) + '"></div>' +
                '<form class="as-form cw-as__form" novalidate>' +
                    '<textarea class="as-input form-control cw-as__input" rows="1" maxlength="1000" aria-label="' + esc(this.t('placeholder')) + '" placeholder="' + esc(this.t('placeholder')) + '"></textarea>' +
                    '<button type="submit" class="cw-btn cw-btn--primary as-send">' + esc(this.t('send')) + '</button>' +
                '</form>' +
                '<div class="as-foot cw-as__foot"></div>'
            );
            this.setFormEnabled(false);
            this.loadStatus();
        },

        // ---------------------------------------------------------------- states

        parseError: function (xhr) {
            var body = null;

            try { body = xhr && xhr.responseText ? JSON.parse(xhr.responseText) : (xhr && xhr.responseJSON) || null; } catch (e) { body = null; }
            if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }

            return {status: xhr ? xhr.status : 0, body: body};
        },

        stateHtml: function (kind) {
            var map = {
                not_configured: {icon: 'fas fa-plug', title: 'notConfigured', hint: 'notConfiguredHint', admin: true},
                module_off: {icon: 'fas fa-toggle-off', title: 'moduleOff', hint: 'moduleOffHint'},
                no_access: {icon: 'fas fa-lock', title: 'noAccess', hint: 'noAccessHint'},
                limit_day: {icon: 'fas fa-hourglass-end', title: 'limitDay', hint: 'limitDayHint'},
                limit_month: {icon: 'fas fa-hourglass-end', title: 'limitMonth', hint: 'limitMonthHint'},
                busy: {icon: 'fas fa-clock', title: 'busy', hint: 'busyHint'},
            };

            if (kind === 'auth') {
                return State.error({auth: true, title: this.t('sessionExpired'), loginLabel: this.t('relogin')}).replace('action-relogin', 'action-relogin as-relogin');
            }

            if (kind === 'error') {
                return State.error({title: this.t('errorTitle'), hint: this.t('errorHint'), retryLabel: this.t('retry')}).replace('action-retry', 'action-retry as-retry');
            }

            var m = map[kind];
            var action = m.admin && this.status && this.status.canAdmin
                ? '<div class="cw-actions"><button type="button" class="cw-btn cw-btn--primary as-admin">' + esc(this.t('openSettings')) + '</button></div>' : '';

            return State.empty({icon: m.icon, title: this.t(m.title), hint: this.t(m.hint), actionHtml: action});
        },

        /** Whole-panel state (nothing to ask until it is fixed). */
        showBlocked: function (kind) {
            this.$root.find('.as-state').html(this.stateHtml(kind));
            this.$root.find('.as-chips, .as-form, .as-log').hide();
            this.setFormEnabled(false);

            // module off / no right: nothing else of the assistant is shown
            if (kind === 'module_off' || kind === 'no_access') { this.$root.find('.as-foot').empty(); } else { this.paintFoot(); }
        },

        showReady: function () {
            this.$root.find('.as-state').empty();
            this.$root.find('.as-chips, .as-form').show();
            this.$root.find('.as-log').css('display', '');
            this.setFormEnabled(true);
            this.paintChips();
            this.paintFoot();
        },

        setFormEnabled: function (on) {
            this.$root.find('.as-input, .as-send').prop('disabled', !on);
        },

        loadStatus: function () {
            this.$root.find('.as-state').html(State.loading(this.t('loading')));

            Espo.Ajax.postRequest('Assistant/action/status', {}).then(function (s) {
                this.status = s;
                s.configured ? this.showReady() : this.showBlocked('not_configured');

                if (s.configured && s.limits && s.limits.leftToday <= 0) { this.showLimit('limit_day'); }
            }.bind(this)).catch(function (xhr) {
                var e = this.parseError(xhr);
                this.showBlocked(A.stateOf(e.status, e.body));
            }.bind(this));
        },

        showLimit: function (kind) {
            this.$root.find('.as-state').html(this.stateHtml(kind));
            this.$root.find('.as-chips').hide();
            this.setFormEnabled(false);
        },

        paintChips: function () {
            this.$root.find('.as-chips').html('<span class="cw-meta">' + esc(this.t('try')) + '</span>' + A.SUGGESTIONS.map(function (k) {
                return '<button type="button" class="cw-as__chip as-chip" data-q="' + esc(this.t('q_' + k.slice(1))) + '">' + esc(this.t(k)) + '</button>';
            }, this).join(''));
        },

        paintFoot: function () {
            var s = this.status, left = s && s.configured && s.limits ? '<span class="as-left">' + esc(this.t('left', {n: s.limits.leftToday})) + '</span> · ' : '';
            var tools = s && s.configured
                ? '<button type="button" class="cw-btn as-history">' + esc(this.t('history')) + '</button>' : '';

            this.$root.find('.as-foot').html(
                '<div class="cw-meta">' + left + esc(this.t('aiNotice')) + '</div>' +
                '<div class="cw-meta">' + esc(this.t('privacy')) + '</div>' + (tools ? '<div class="cw-actions">' + tools + '</div>' : '') +
                '<div class="as-hist"></div>');
        },

        // ---------------------------------------------------------------- asking

        submit: function () {
            var $i = this.$root.find('.as-input'), q = $.trim($i.val());

            if (q) { this.ask(q); }
        },

        bubble: function (cls, html) {
            var $m = $('<div class="cw-as__msg cw-as__msg--' + cls + '"></div>').html(html);
            var $log = this.$root.find('.as-log');

            $log.append($m);
            $log.scrollTop($log[0].scrollHeight);

            return $m;
        },

        ask: function (q) {
            if (this.busy) { return; }

            if (q.length > ((this.status && this.status.questionMax) || 1000)) {
                Espo.Ui.warning(this.t('tooLong', {n: (this.status && this.status.questionMax) || 1000}));

                return;
            }

            this.busy = true;
            this.setFormEnabled(false);
            this.$root.find('.as-input').val('');
            this.$root.find('.as-chips').hide();
            this.bubble('user', '<div class="cw-meta">' + esc(this.t('you')) + '</div><p>' + esc(q).replace(/\n/g, '<br>') + '</p>');

            var $wait = this.bubble('bot', State.loading(this.t('thinking')) + '<div class="cw-meta">' + esc(this.t('thinking')) + '</div>');

            Espo.Ajax.postRequest('Assistant/action/ask', {question: q, page: this.fromPage}, {timeout: 135000}).then(function (r) {
                this.busy = false;

                if (r.ok === false && r.state === 'not_configured') {
                    $wait.remove();
                    this.showBlocked('not_configured');

                    return;
                }

                $wait.replaceWith(this.answerHtml(r));
                this.after();
            }.bind(this)).catch(function (xhr) {
                this.busy = false;
                var e = this.parseError(xhr), kind = A.stateOf(e.status, e.body);

                $wait.remove();

                if (kind === 'module_off' || kind === 'no_access') { this.showBlocked(kind); return; }

                this.bubble('bot', this.stateHtml(kind === 'ok' ? 'error' : kind));
                this.after(kind);
            }.bind(this));
        },

        after: function (kind) {
            this.refreshLeft();

            if (kind === 'limit_day' || kind === 'limit_month') { this.showLimit(kind); return; }

            this.$root.find('.as-chips').show();
            this.setFormEnabled(true);
            this.$root.find('.as-input').trigger('focus');
        },

        refreshLeft: function () {
            Espo.Ajax.postRequest('Assistant/action/status', {}).then(function (s) {
                this.status = s;
                this.$root.find('.as-left').text(this.t('left', {n: s.limits.leftToday}));

                if (s.limits.leftToday <= 0) { this.showLimit('limit_day'); }
            }.bind(this)).catch(function () {});
        },

        // ---------------------------------------------------------------- answers

        sourcesHtml: function (sources) {
            if (!sources || !sources.length) { return ''; }

            return '<div class="cw-as__sources"><span class="cw-meta">' + esc(this.t('sources')) + ':</span> ' + sources.map(function (s) {
                var label = this.translate('tool_' + s.tool, 'labels', 'Assistant');
                var per = s.period && s.period.from ? ' · ' + s.period.from + (s.period.to && s.period.to !== s.period.from ? ' → ' + s.period.to : '') : '';
                var title = s.source ? ' title="' + esc(s.source) + '"' : '';

                return '<span class="cw-chip ' + (s.error ? 'cw-chip--unpaid' : 'cw-chip--pending') + '"' + title + '>' + esc(label + per) + '</span>';
            }, this).join(' ') + '</div>';
        },

        draftsHtml: function (drafts, history) {
            if (!drafts || !drafts.length) { return ''; }

            return '<div class="cw-as__drafts">' + drafts.map(function (d) {
                var i = ++this.draftSeq;
                var label = this.t(d.entityType === 'Mailing' ? 'draftMailing' : 'draftReservation');

                if (history) { return '<div class="cw-meta">' + esc(label + (d.summary ? ': ' + d.summary : '')) + '</div>'; }

                this.drafts[i] = d;

                return '<button type="button" class="cw-btn cw-btn--primary as-draft" data-i="' + i + '"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> ' +
                    esc(this.t('openDraft')) + ': ' + esc(label) + '</button><div class="cw-meta">' + esc(d.summary || '') + '</div>';
            }, this).join('') + '<div class="cw-meta">' + esc(this.t('draftNote')) + '</div></div>';
        },

        answerHtml: function (r, history) {
            var note = '';

            if (r.stopped && r.stopped !== 'answered') {
                var key = 'stopped_' + r.stopped, txt = this.translate(key, 'labels', 'Assistant');

                note = '<div class="cw-as__warn" role="status">' + esc(txt === key ? this.t('errorTitle') : txt) + '</div>';
            }

            var unsourced = r.unsourced && r.unsourced.length
                ? '<div class="cw-as__warn" role="status"><strong>' + esc(this.t('unsourced')) + ':</strong> ' + esc(r.unsourced.join(', ')) +
                  '<div class="cw-meta">' + esc(this.t('unsourcedHint')) + '</div></div>' : '';

            var cost = !history && r.usage && this.status && this.status.canAdmin
                ? '<div class="cw-meta">' + esc(this.t('costNote', {tokens: (r.usage.tokensIn || 0) + (r.usage.tokensOut || 0) + (r.usage.cacheRead || 0), eur: (r.usage.costEur || 0).toFixed(4)})) + '</div>' : '';

            return '<div class="cw-as__msg cw-as__msg--bot"><div class="cw-meta">' + esc(this.t('answer')) + '</div>' +
                '<div class="cw-as__text">' + A.renderMarkdown(r.answer) + '</div>' + note + unsourced +
                this.draftsHtml(r.drafts, history) + this.sourcesHtml(r.sources) + cost + '</div>';
        },

        openDraft: function (i) {
            var t = A.draftTarget(this.drafts[i]);

            if (!t) { return; }

            var router = this.getRouter();

            router.navigate('#' + t.scope + '/create', {trigger: false});
            router.dispatch(t.scope, 'create', {attributes: t.attributes, returnUrl: window.location.hash || '#Home'});
        },

        // ---------------------------------------------------------------- history

        toggleHistory: function () {
            var $h = this.$root.find('.as-hist');

            if ($h.children().length) { $h.empty(); return; }

            $h.html(State.loading(this.t('loading')));

            Espo.Ajax.postRequest('Assistant/action/history', {limit: 20}).then(function (r) {
                if (!r.list.length) { $h.html('<p class="cw-meta">' + esc(this.t('historyEmpty')) + '</p>'); return; }

                $h.html('<div class="cw-as__history">' + r.list.map(function (x) {
                    return '<div class="cw-as__hrow"><div class="cw-meta">' + esc(x.at) + '</div><div class="cw-as__hq">' + esc(x.question) + '</div>' +
                        '<div class="cw-as__text">' + A.renderMarkdown(x.answer) + '</div>' + this.draftsHtml(x.drafts, true) + '</div>';
                }, this).join('') + '</div><div class="cw-actions"><button type="button" class="cw-btn as-clear">' + esc(this.t('clearHistory')) + '</button></div>');
            }.bind(this)).catch(function (xhr) {
                this.parseError(xhr);
                $h.html('<p class="cw-meta">' + esc(this.t('errorTitle')) + '</p>');
            }.bind(this));
        },

        clearHistory: function () {
            Espo.Ui.confirm(this.t('clearHistoryConfirm'), {confirmText: this.t('clearHistory'), cancelText: this.translate('Cancel')}, function () {
                Espo.Ajax.postRequest('Assistant/action/deleteHistory', {}).then(function () {
                    Espo.Ui.success(this.t('cleared'));
                    this.$root.find('.as-hist').empty();
                    this.$root.find('.as-log').empty();
                    this.refreshLeft();
                }.bind(this)).catch(function (xhr) { this.parseError(xhr); }.bind(this));
            }.bind(this));
        },
    });
});
