/**
 * Assistant: pure helpers (no DOM, no Espo) shared by the chat panel, the dashlet and the page,
 * and unit-tested in Node (tests/assistant.test.js).
 *
 *  - renderMarkdown: the ONLY way model text reaches the page. Everything is HTML-escaped first;
 *    only a fixed subset is then re-introduced (paragraphs, **bold**, `code`, bullet and numbered
 *    lists, line breaks). No links, no images, no raw HTML - whatever the model (or a customer's
 *    name inside its answer) contains stays inert text.
 *  - stateOf: which of the panel's states a server answer means.
 *  - draftTarget: a draft is opened only for a known entity, with plain scalar attributes.
 */
define(function () {

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function inline(escaped) {
        return escaped
            .replace(/`([^`\n]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    }

    function renderMarkdown(text) {
        var lines = String(text == null ? '' : text).replace(/\r\n?/g, '\n').split('\n');
        var out = [], para = [], list = null;

        function flushPara() {
            if (para.length) { out.push('<p>' + para.map(function (l) { return inline(esc(l)); }).join('<br>') + '</p>'); para = []; }
        }
        function flushList() {
            if (list) {
                out.push('<' + list.tag + '>' + list.items.map(function (i) { return '<li>' + inline(esc(i)) + '</li>'; }).join('') + '</' + list.tag + '>');
                list = null;
            }
        }

        lines.forEach(function (line) {
            var ul = /^\s{0,3}(?:[-*•])\s+(.*)$/.exec(line);
            var ol = /^\s{0,3}\d{1,3}[.)]\s+(.*)$/.exec(line);

            if (ul || ol) {
                flushPara();
                var tag = ul ? 'ul' : 'ol';
                if (list && list.tag !== tag) { flushList(); }
                if (!list) { list = {tag: tag, items: []}; }
                list.items.push((ul || ol)[1]);
            } else if (/^\s*$/.test(line)) {
                flushPara(); flushList();
            } else {
                flushList();
                para.push(line.replace(/^#{1,6}\s+/, ''));
            }
        });

        flushPara(); flushList();

        return out.join('');
    }

    /**
     * What a failed or special server answer means for the panel.
     * `status` is the HTTP status, `body` the parsed JSON (or null).
     * -> 'ok' | 'not_configured' | 'module_off' | 'no_access' | 'limit_day' | 'limit_month' | 'busy' | 'auth' | 'error'
     */
    function stateOf(status, body) {
        body = body || {};
        var code = body.state || body.error || '';

        if (status === 200 && code === 'not_configured') { return 'not_configured'; }
        if (status === 200 && body.ok !== false) { return 'ok'; }
        if (code === 'module_disabled') { return 'module_off'; }
        if (code === 'forbidden') { return 'no_access'; }
        if (code === 'busy') { return 'busy'; }
        if (code === 'limit_user_day') { return 'limit_day'; }
        if (code === 'limit_month_questions' || code === 'limit_month_tokens') { return 'limit_month'; }
        if (status === 401) { return 'auth'; }

        return 'error';
    }

    var DRAFT_SCOPES = {Mailing: true, WashOrder: true};

    /** -> {scope, attributes} or null when the draft is not safe to open. */
    function draftTarget(d) {
        if (!d || typeof d !== 'object' || !DRAFT_SCOPES[d.entityType] || d.action !== 'create') { return null; }
        var src = d.attributes && typeof d.attributes === 'object' ? d.attributes : {}, attrs = {};

        Object.keys(src).forEach(function (k) {
            var v = src[k];
            if (/^[A-Za-z][A-Za-z0-9]*$/.test(k) && (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean')) { attrs[k] = v; }
        });

        return {scope: d.entityType, attributes: attrs};
    }

    function fill(str, vars) {
        return String(str).replace(/\{(\w+)\}/g, function (m, k) { return vars && vars[k] != null ? vars[k] : m; });
    }

    var SUGGESTIONS = ['sRevenue', 'sOrder', 'sInactive', 'sUnpaid', 'sToday'];

    return {esc: esc, renderMarkdown: renderMarkdown, stateOf: stateOf, draftTarget: draftTarget, fill: fill, SUGGESTIONS: SUGGESTIONS};
});
