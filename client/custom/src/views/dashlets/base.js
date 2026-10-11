/**
 * Base for all CarWash dashlets: adds "width" and "height" options so a dashlet can be sized
 * from its own settings dialog, not only by dragging its corner on the dashboard.
 *
 * The dashboard is a 12-column gridstack whose layout units are multiplied by
 * WIDTH_MULTIPLIER (3) and HEIGHT_MULTIPLIER (4). So layout width 2 = one column (half the
 * board), 4 = full width. Applying a size updates the grid item; gridstack then fires
 * "change", and the dashboard persists the layout itself — we never write it directly.
 *
 * Both options default to empty = "as dragged": an untouched dashlet keeps whatever size it
 * has on the board.
 */
define(['views/dashlets/abstract/base', 'custom:lib/state', 'custom:lib/format'], function (Dep, State, Fmt) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.installErrorLogger();
            this.ensureCwStyles();

            // after:render fires whatever a subclass does in afterRender, so subclasses
            // never have to remember to call up.
            this.on('after:render', function () {
                this.applyDashletSize();
                this.observeFit();
            }, this);

            this.once('remove', function () {
                if (this.fitObserver) { this.fitObserver.disconnect(); this.fitObserver = null; }
            }, this);
        },

        /**
         * The dashlet markup is styled by --cw-* tokens, which only the CarWash themes define. A user
         * who keeps a stock personal theme would get an unstyled board, so in that case load the
         * standalone token + component sheet (light or dark to match the active theme).
         */
        ensureCwStyles: function () {
            if (document.getElementById('cw-standalone-css')) { return; }

            var probe = window.getComputedStyle(document.documentElement).getPropertyValue('--cw-panel');

            if (probe && probe.trim()) { return; }

            var dark = false;

            try { dark = !!this.getThemeManager().getParam('isDark'); } catch (e) { /* default light */ }

            var link = document.createElement('link');

            link.id = 'cw-standalone-css';
            link.rel = 'stylesheet';
            link.href = 'client/custom/css/cw-standalone' + (dark ? '-dark' : '') + '.css?r=' + (this.getConfig().get('cacheTimestamp') || '');
            document.head.appendChild(link);
        },

        /**
         * Send uncaught browser errors to the server log (CarWash/action/logClientError),
         * so a glitch a user reports hours later can be looked up in data/logs instead of
         * reproduced by guesswork. Installed once per page, never more.
         */
        installErrorLogger: function () {
            if (window.__cwErrorLogger) {
                return;
            }

            window.__cwErrorLogger = true;

            var send = function (where, message, stack) {
                try {
                    Espo.Ajax.postRequest('CarWash/action/logClientError', {
                        where: where,
                        message: String(message || '').slice(0, 500),
                        stack: String(stack || '').slice(0, 2000),
                        url: window.location.hash,
                    });
                } catch (ignore) {}
            };

            window.addEventListener('error', function (e) {
                send('window.onerror', e.message, e.error && e.error.stack);
            });

            window.addEventListener('unhandledrejection', function (e) {
                var r = e.reason;
                send('unhandledrejection', r && (r.message || r.statusText || r), r && r.stack);
            });
        },

        // A dashlet without an explicit title shows its own name, so every card has a heading.
        getTitle: function () {
            return this.getOption('title') || this.translate(this.name, 'dashlets', 'Global');
        },

        // ---- shared dashlet states (loading / empty / error), see lib/state.js ----

        cwState: State,
        cwFmt: Fmt,

        /** Translate from the shared "CarWash" scope. */
        cwT: function (name) { return this.translate(name, 'labels', 'CarWash'); },

        /** Money in the user's language: "1 234,50 €". */
        money: function (v) { return Fmt.fmtMoney(v, this.getPreferences().get('language') || this.getConfig().get('language')); },

        cwShowLoading: function ($body) {
            $body.html(State.loading(this.cwT('loading')));
        },

        /**
         * Render the error state for a rejected request. `retry` is called by the button;
         * a 401/403 (idle logout) shows "session expired" and a button that reloads to the login.
         */
        cwShowError: function ($body, xhr, retry) {
            // The stock handler would pop its own notification on top of ours.
            if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }

            var auth = State.isAuthError(xhr);

            $body.html(State.error({
                auth: auth,
                title: this.cwT(auth ? 'sessionExpired' : 'loadError'),
                hint: this.cwT(auth ? 'sessionExpiredHint' : 'loadErrorHint'),
                retryLabel: this.cwT('retry'),
                loginLabel: this.cwT('relogin'),
            }));

            $body.find('.action-retry').on('click', function () { retry && retry(); });
            $body.find('.action-relogin').on('click', function () { window.location.reload(); });
        },

        /**
         * Shrink/grow the dashlet to its content, so a dashlet is never a tall box with a
         * strip of content in it (D6). Opt in with `autoFit: true`; an explicit Height option
         * wins. Persisting is left to the dashboard's own "change" handling.
         */
        /** Re-fit whenever the content's own size changes (wrapping, fonts, data arriving). */
        observeFit: function () {
            if (!this.autoFit || typeof ResizeObserver === 'undefined') { return; }

            if (this.fitObserver) { this.fitObserver.disconnect(); }

            var el = this.$el.find('[data-cw-fit]').get(0);

            if (!el) { return; }

            var timer = null;

            this.fitObserver = new ResizeObserver(function () {
                clearTimeout(timer);
                timer = setTimeout(this.fitToContent.bind(this), 120);
            }.bind(this));

            this.fitObserver.observe(el);
        },

        fitToContent: function () {
            if (!this.autoFit || this.getOption('dashletHeight')) { return; }

            try {
                var container = this.getParentView();
                var dashboard = container ? container.getParentView() : null;

                if (!dashboard || !dashboard.grid || !dashboard.$gridstack) { return; }

                var $item = dashboard.$gridstack.find('.grid-stack-item[data-id="' + container.id + '"]');
                var $content = this.$el.find('[data-cw-fit]').first();

                if (!$item.length || !$content.length) { return; }

                var rows = parseInt($item.attr('gs-h'), 10) || 1;
                var margin = parseInt(dashboard.grid.opts && dashboard.grid.opts.margin, 10) || 0;
                var rowPx = ($item.outerHeight() + margin) / rows;
                // overhead = what the item spends on things other than our scrolling body
                var $scroll = this.$el.closest('.dashlet-body');
                var overhead = $item.outerHeight() - ($scroll.length ? $scroll.innerHeight() : this.$el.innerHeight());
                var need = Math.max(3, Math.ceil((overhead + $content.outerHeight(true) + margin + 16) / rowPx));

                if (need !== rows) {
                    dashboard.grid.update($item.get(0), {h: need});
                }
            } catch (e) {
                console.warn('CarWash: could not fit dashlet to content', e);
            }
        },

        applyDashletSize: function () {
            var width = this.getOption('dashletWidth');
            var height = this.getOption('dashletHeight');

            if (!width && !height) {
                return;
            }

            try {
                var container = this.getParentView();            // views/dashlet
                var dashboard = container ? container.getParentView() : null;

                if (!dashboard || !dashboard.grid || !dashboard.$gridstack) {
                    return;
                }

                var $item = dashboard.$gridstack.find('.grid-stack-item[data-id="' + container.id + '"]');

                if (!$item.length) {
                    return;
                }

                var params = {};

                if (width) {
                    params.w = parseInt(width, 10) * (dashboard.WIDTH_MULTIPLIER || 3);
                }

                if (height) {
                    params.h = parseInt(height, 10) * (dashboard.HEIGHT_MULTIPLIER || 4);
                }

                // Already that size — don't touch the grid, or every render would trigger
                // a layout save.
                var sameW = params.w == null || params.w === parseInt($item.attr('gs-w'), 10);
                var sameH = params.h == null || params.h === parseInt($item.attr('gs-h'), 10);

                if (sameW && sameH) {
                    return;
                }

                dashboard.grid.update($item.get(0), params);
            } catch (e) {
                // Sizing is a convenience — a gridstack API change must never break the dashlet.
                console.warn('CarWash: could not apply dashlet size', e);
            }
        },

    });
});
