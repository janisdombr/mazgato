/**
 * Logic of the Quick start wizard (`#Admin/quickStart`) with no DOM, so tests/quickstart.test.js can run it in Node.
 * The page keeps ONE model (the answer of QuickStart/action/state plus the editor's marks) and turns it into the
 * payload of QuickStart/action/save. The server validates again - this is for the operator's convenience only.
 *
 * Model marks: `_new` (not saved yet; has `key` "new:xyz"), `_delete` (remove on save), `_dirty` (touched).
 */
define(function () {

    var seq = 0;

    function newKey() {
        seq += 1;

        return 'new:' + Date.now().toString(36) + seq;
    }

    /** "12,5" -> 12.5 ; "" -> null ; garbage / negative / infinite -> NaN. */
    function parseNum(v) {
        if (v == null) { return null; }

        if (typeof v === 'number') { return isFinite(v) && v >= 0 ? v : NaN; }

        var s = String(v).trim().replace(/\s/g, '').replace(',', '.');

        if (s === '') { return null; }

        if (!/^\d+(\.\d+)?$/.test(s)) { return NaN; }

        var n = Number(s);

        return isFinite(n) ? n : NaN;
    }

    var blanks = {
        employee: function () { return {key: newKey(), _new: true, name: '', status: 'Hired', workRoleId: null, salaryRate: null}; },
        role: function () { return {key: newKey(), _new: true, name: '', isActive: true, defaultEmployeeId: null}; },
        work: function () { return {key: newKey(), _new: true, name: '', isActive: true, defaultRoleId: null, baseRate: null}; },
        service: function (category) {
            return {key: newKey(), _new: true, name: '', category: category === 'Add-on' ? 'Add-on' : 'Base', isActive: true,
                priceRetail: null, priceCorporate: null, durationMinutes: null, lines: []};
        },
        line: function (workId) { return {workId: workId, roleId: null, rate: null}; },
    };

    var LISTS = {roles: 'role', employees: 'employee', works: 'work', services: 'service'};

    /** Problems the operator can fix before pressing Save: [{list, ref, field, code}] */
    function validate(model) {
        var out = [];

        function bad(list, it, field, code) { out.push({list: list, ref: it.id || it.key, field: field, code: code}); }

        Object.keys(LISTS).forEach(function (list) {
            (model[list] || []).forEach(function (it) {
                if (it._delete) { return; }

                if (!String(it.name || '').trim()) { bad(list, it, 'name', 'name'); }

                ['salaryRate', 'baseRate', 'priceRetail', 'priceCorporate', 'durationMinutes'].forEach(function (f) {
                    if (f in it && isNaN(parseNum(it[f]))) { bad(list, it, f, 'number'); }
                });

                if (list === 'services') {
                    var seen = {};

                    (it.lines || []).forEach(function (l) {
                        if (!l.workId) { bad(list, it, 'lines', 'line_no_work'); }

                        if (l.workId && seen[l.workId]) { bad(list, it, 'lines', 'line_duplicate'); }

                        seen[l.workId] = true;

                        if (isNaN(parseNum(l.rate))) { bad(list, it, 'lines', 'number'); }
                    });
                }
            });
        });

        return out;
    }

    function clean(it, drop) {
        var o = {};

        Object.keys(it).forEach(function (k) {
            if (drop.indexOf(k) < 0) { o[k] = it[k]; }
        });

        return o;
    }

    /** The save body: only what the server needs; a deleted never-saved row disappears. */
    function buildPayload(model) {
        var p = {};

        Object.keys(LISTS).forEach(function (list) {
            p[list] = (model[list] || []).filter(function (it) { return !(it._new && it._delete); }).map(function (it) {
                var o = clean(it, ['_new', '_dirty', 'workName', 'sortOrder']);

                if (it._delete) { return {id: it.id, _delete: true}; }

                if (!it._new) { delete o.key; }

                ['salaryRate', 'baseRate', 'priceRetail', 'priceCorporate', 'durationMinutes'].forEach(function (f) {
                    if (f in o) { o[f] = parseNum(o[f]); }
                });

                if (list === 'services') {
                    o.lines = (it.lines || []).map(function (l) {
                        return {workId: l.workId, roleId: l.roleId || null, rate: parseNum(l.rate)};
                    });
                }

                return o;
            });
        });

        return p;
    }

    /** Works not yet in a service (for the "add work" select). */
    function freeWorks(model, service) {
        var used = {};

        (service.lines || []).forEach(function (l) { used[l.workId] = true; });

        return (model.works || []).filter(function (w) { return !w._delete && w.isActive !== false && !used[w.id || w.key]; });
    }

    /** Is there something to save? */
    function isDirty(model) {
        return Object.keys(LISTS).some(function (list) {
            return (model[list] || []).some(function (it) {
                return it._dirty || (it._new && !it._delete) || (it._delete && !it._new);
            });
        });
    }

    /** issues -> {error: n, warning: n}, plus the first target (to jump to). */
    function summarize(issues) {
        var s = {errors: 0, warnings: 0, first: null};

        (issues || []).forEach(function (i) {
            if (i.severity === 'error') { s.errors += 1; } else { s.warnings += 1; }

            if (!s.first || (i.severity === 'error' && s.first.severity !== 'error')) { s.first = i; }
        });

        return s;
    }

    /** "service:abc" -> {kind: 'service', id: 'abc'}; "services" -> {kind: 'services', id: null} */
    function parseFocus(f) {
        var m = String(f || '').split(':');

        return {kind: m[0] || '', id: m[1] || null};
    }

    return {newKey: newKey, parseNum: parseNum, blank: blanks, validate: validate, buildPayload: buildPayload,
        freeWorks: freeWorks, isDirty: isDirty, summarize: summarize, parseFocus: parseFocus};
});
