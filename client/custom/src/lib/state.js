/**
 * Loading / empty / error markup shared by every dashlet (D20-D24), so the three states
 * look the same everywhere and a failed request is never a silent "…".
 *
 * Every function returns an HTML string built from classes only (see cw-components.css).
 * Texts are passed in already translated; this module knows no language.
 */
define(function () {

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var api = {

        esc: esc,

        /** Three shimmering rows + a text for assistive tech. */
        loading: function (text) {
            return '<div class="cw-skeleton" role="status" aria-live="polite" aria-label="' + esc(text) + '">' +
                '<div class="cw-skeleton__row"></div><div class="cw-skeleton__row"></div><div class="cw-skeleton__row"></div>' +
                '</div>';
        },

        /** opts: {icon, title, hint, actionHtml} */
        empty: function (opts) {
            opts = opts || {};

            return '<div class="cw-state cw-state--empty">' +
                '<i class="cw-state__icon ' + esc(opts.icon || 'fas fa-car-side') + '" aria-hidden="true"></i>' +
                '<div class="cw-state__title">' + esc(opts.title) + '</div>' +
                (opts.hint ? '<div class="cw-state__hint">' + esc(opts.hint) + '</div>' : '') +
                (opts.actionHtml || '') +
                '</div>';
        },

        /**
         * Error block. opts: {auth: bool, title, hint, retryLabel, loginLabel}. A 401 (the
         * 10-minute idle logout) offers "sign in again"; anything else offers "retry".
         */
        error: function (opts) {
            opts = opts || {};

            var action = opts.auth
                ? '<button type="button" class="cw-btn cw-btn--primary action-relogin">' + esc(opts.loginLabel) + '</button>'
                : '<button type="button" class="cw-btn cw-btn--primary action-retry">' + esc(opts.retryLabel) + '</button>';

            return '<div class="cw-state cw-state--' + (opts.auth ? 'auth' : 'error') + '" role="alert">' +
                '<i class="cw-state__icon fas ' + (opts.auth ? 'fa-lock' : 'fa-triangle-exclamation') + '" aria-hidden="true"></i>' +
                '<div class="cw-state__title">' + esc(opts.title) + '</div>' +
                (opts.hint ? '<div class="cw-state__hint">' + esc(opts.hint) + '</div>' : '') +
                '<div class="cw-actions">' + action + '</div>' +
                '</div>';
        },

        /** True when a rejected Espo.Ajax request means "the session is gone". */
        isAuthError: function (xhr) {
            return !!xhr && (xhr.status === 401 || xhr.status === 403);
        },
    };

    return api;
});
