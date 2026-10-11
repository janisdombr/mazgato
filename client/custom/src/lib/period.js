/**
 * One reporting period for the whole Statistics tab (D25): Today · 7 days · Month · Quarter
 * · Range. The choice lives in sessionStorage and is announced with a `cw:period` window
 * event, so Statistics / Payroll / Supply Usage all follow one switch. Dates are LOCAL
 * calendar days "YYYY-MM-DD" — toISOString() would shift the day for Europe/Riga.
 */
define(function () {

    var KEY = 'cwPeriod';
    var KEYS = ['today', 'week', 'month', 'quarter', 'custom'];

    function iso(d) {
        var m = d.getMonth() + 1, day = d.getDate();

        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }

    var api = {

        KEYS: KEYS,
        iso: iso,

        /** {from, to} for a preset key, relative to `now`. */
        range: function (key, now) {
            now = now || new Date();
            var y = now.getFullYear(), m = now.getMonth();

            switch (key) {
                case 'today': return {from: iso(now), to: iso(now)};
                case 'week': return {from: iso(new Date(y, m, now.getDate() - 6)), to: iso(now)};
                case 'quarter': {
                    var q = Math.floor(m / 3) * 3;

                    return {from: iso(new Date(y, q, 1)), to: iso(new Date(y, q + 3, 0))};
                }
                default: return {from: iso(new Date(y, m, 1)), to: iso(new Date(y, m + 1, 0))};
            }
        },

        /** Current period {key, from, to}. Presets are recomputed so "today" never goes stale. */
        get: function () {
            var saved = null;

            try { saved = JSON.parse(window.sessionStorage.getItem(KEY) || 'null'); } catch (e) { /* ignore */ }

            if (saved && saved.key === 'custom' && saved.from && saved.to) { return saved; }

            var key = saved && KEYS.indexOf(saved.key) >= 0 && saved.key !== 'custom' ? saved.key : 'month';
            var r = api.range(key);

            return {key: key, from: r.from, to: r.to};
        },

        /** Store and announce. `p` = {key, from?, to?}. */
        set: function (p) {
            var r = p.key === 'custom' ? {from: p.from, to: p.to} : api.range(p.key);
            var value = {key: p.key, from: r.from, to: r.to};

            try { window.sessionStorage.setItem(KEY, JSON.stringify(value)); } catch (e) { /* ignore */ }

            window.dispatchEvent(new CustomEvent('cw:period', {detail: value}));

            return value;
        },

        /** Subscribe to changes; returns an unsubscribe function. */
        subscribe: function (fn) {
            var h = function (e) { fn(e.detail); };

            window.addEventListener('cw:period', h);

            return function () { window.removeEventListener('cw:period', h); };
        },

        /** API payload for the CarWash/action/* report endpoints. */
        payload: function (p) {
            p = p || api.get();

            return {dateFrom: p.from + ' 00:00:00', dateTo: p.to + ' 23:59:59'};
        },
    };

    return api;
});
