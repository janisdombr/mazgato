/**
 * Which two pictures of an order to compare, and where each one is fetched from. Pure (no DOM), so
 * tests/anpr.test.js covers it.
 *
 *   entry -> exit    the camera pictures at the gate (earliest Entry, latest Exit)
 *   before -> after  the worker's tablet pictures (earliest Before, latest After)
 * Camera pictures go through `cwMedia` (the CRM's ACL check + access journal); the rest through the
 * stock image entry point.
 */
define(function () {

    function url(photo) {
        if (!photo) { return null; }
        if (photo.source === 'Camera') { return '?entryPoint=cwMedia&type=orderPhoto&id=' + encodeURIComponent(photo.id); }
        return photo.fileId ? '?entryPoint=image&size=large&id=' + encodeURIComponent(photo.fileId) : null;
    }

    function byTime(a, b) { return String(a.takenAt || a.createdAt || '').localeCompare(String(b.takenAt || b.createdAt || '')); }

    function pick(list, kind, last) {
        var rows = list.filter(function (p) { return p.kind === kind; }).sort(byTime);
        return rows.length ? rows[last ? rows.length - 1 : 0] : null;
    }

    /** -> {entryExit: {from, to}|null, beforeAfter: {from, to}|null}; a pair exists when at least one side does. */
    function pairs(list) {
        list = list || [];
        var ee = {from: pick(list, 'Entry', false), to: pick(list, 'Exit', true)};
        var ba = {from: pick(list, 'Before', false), to: pick(list, 'After', true)};

        return {
            entryExit: ee.from || ee.to ? ee : null,
            beforeAfter: ba.from || ba.to ? ba : null,
        };
    }

    return {url: url, pairs: pairs, byTime: byTime};
});
