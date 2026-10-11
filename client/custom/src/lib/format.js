/**
 * One source for how the CRM prints money, plates and durations (D15/D16).
 * Pure functions, no DOM, no Espo globals — covered by tests/client.test.js in Node.
 *
 *   fmtMoney(1234.5)        -> "1 234,50 €"   (lv / ru: NBSP thousands, comma decimals, € after)
 *   fmtMoney(1234.5, 'en_US') -> "€1,234.50"
 */
define(function () {

    var NBSP = ' ';

    function group(intPart, sep) {
        return intPart.replace(/\B(?=(\d{3})+(?!\d))/g, sep);
    }

    var api = {

        /** Number or numeric string -> formatted EUR amount. null/'' -> '' (never "0 €" for "unknown"). */
        fmtMoney: function (value, lang) {
            if (value === null || value === undefined || value === '' || isNaN(Number(value))) {
                return '';
            }

            var n = Math.round(Number(value) * 100) / 100;
            var neg = n < 0;
            var parts = Math.abs(n).toFixed(2).split('.');
            var en = lang === 'en_US' || lang === 'en_GB';
            var s;

            if (en) {
                s = '€' + group(parts[0], ',') + '.' + parts[1];
            } else {
                s = group(parts[0], NBSP) + ',' + parts[1] + NBSP + '€';
            }

            return (neg ? '−' : '') + s;
        },

        /** "ke-1234" -> "KE1234": the same normalisation the server applies to plates. */
        fmtPlate: function (value) {
            return String(value == null ? '' : value).replace(/[\s\-]+/g, '').toUpperCase();
        },

        /** Whole minutes -> "37 min" / "1 h 05 min" (unit words are passed in, so they stay translatable). */
        fmtDuration: function (minutes, units) {
            units = units || {};
            var m = Math.max(0, Math.floor(Number(minutes) || 0));
            var min = units.min || 'min';
            var h = units.h || 'h';

            if (m < 60) {
                return m + ' ' + min;
            }

            var rest = m % 60;

            return Math.floor(m / 60) + ' ' + h + ' ' + (rest < 10 ? '0' : '') + rest + ' ' + min;
        },

        /** API datetimes are UTC "YYYY-MM-DD HH:MM:SS"; returns epoch ms (or NaN). */
        parseUtc: function (value) {
            var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(String(value || ''));

            if (!m) {
                return NaN;
            }

            return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
        },

        /** Whole minutes elapsed between a UTC API datetime and `nowMs`. */
        minutesSince: function (value, nowMs) {
            var t = api.parseUtc(value);

            if (isNaN(t)) {
                return null;
            }

            return Math.max(0, Math.floor(((nowMs == null ? Date.now() : nowMs) - t) / 60000));
        },
    };

    return api;
});
