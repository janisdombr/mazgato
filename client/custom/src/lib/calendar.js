/**
 * Logic of the order calendar (#Calendar and the CalendarWeek dashlet), kept free of the DOM so Node can test it
 * (tests/calendar.test.js). Dates are LOCAL calendar days "YYYY-MM-DD" in the wash's time zone; all arithmetic on them is done in
 * UTC so a daylight-saving change can never shift a day (a `Date` in the browser's own zone does, and toISOString() shifts it
 * too). The capacity numbers come from the server (Booking/action/range); nothing here computes a capacity.
 */
define(function () {

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function parse(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s));

        return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) : NaN;
    }

    function fmt(ms) {
        var d = new Date(ms);

        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
    }

    var api = {

        pad: pad,

        isDate: function (s) { return !isNaN(parse(s)) && fmt(parse(s)) === s; },

        addDays: function (s, n) { return fmt(parse(s) + n * 86400000); },

        /** 0 = Sunday .. 6 = Saturday */
        dow: function (s) { return new Date(parse(s)).getUTCDay(); },

        /** The Monday of the week that holds the day. */
        weekStart: function (s) { return api.addDays(s, -((api.dow(s) + 6) % 7)); },

        weekRange: function (s) { var a = api.weekStart(s); return {from: a, to: api.addDays(a, 6)}; },

        /** ISO-8601 week number (the Thursday decides the year). */
        weekNumber: function (s) {
            var thu = parse(api.addDays(api.weekStart(s), 3));
            var jan1 = Date.UTC(new Date(thu).getUTCFullYear(), 0, 1);

            return Math.floor((thu - jan1) / 86400000 / 7) + 1;
        },

        /** The weeks a month view shows: Monday of the week with the 1st .. Sunday of the week with the last day. */
        monthGrid: function (s) {
            var d = new Date(parse(s));
            var first = fmt(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1));
            var last = fmt(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0));
            var from = api.weekStart(first);
            var to = api.addDays(api.weekStart(last), 6);
            var weeks = [];

            for (var w = from; w <= to; w = api.addDays(w, 7)) {
                var row = [];

                for (var i = 0; i < 7; i++) { row.push(api.addDays(w, i)); }

                weeks.push(row);
            }

            return {from: from, to: to, month: first.slice(0, 7), weeks: weeks};
        },

        /** Same day next / previous month, clamped to the month's length (31 Jan + 1 month = 28/29 Feb). */
        addMonths: function (s, n) {
            var d = new Date(parse(s));
            var y = d.getUTCFullYear(), m = d.getUTCMonth() + n;
            var len = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();

            return fmt(Date.UTC(y, m, Math.min(d.getUTCDate(), len)));
        },

        /** The range a view needs: {from, to}. */
        rangeFor: function (view, s) {
            if (view === 'day') { return {from: s, to: s}; }
            if (view === 'week') { return api.weekRange(s); }

            var g = api.monthGrid(s);

            return {from: g.from, to: g.to};
        },

        shift: function (view, s, dir) {
            if (view === 'day') { return api.addDays(s, dir); }
            if (view === 'week') { return api.addDays(s, 7 * dir); }

            return api.addMonths(s, dir);
        },

        /**
         * Colour class of a load: closed / free (< 50 %) / busy (< 85 %) / tight (< 100 %) / full (100 %) / over (more than the
         * capacity, or booked over it knowingly). Thresholds are display only; the server decides what can still be booked.
         */
        level: function (pct, o) {
            o = o || {};

            if (o.closed) { return 'closed'; }
            if (o.over) { return 'over'; }
            if (pct >= 100) { return 'full'; }
            if (pct >= 85) { return 'tight'; }
            if (pct >= 50) { return 'busy'; }

            return 'free';
        },

        /**
         * The start (local minutes from midnight) to prefill for a click on an hour: the hour itself, but never earlier than the next
         * slot after "now" on today's date (a reservation in the past is refused by the server).
         */
        startFor: function (hour, o) {
            o = o || {};
            var m = hour * 60;

            if (o.isToday && o.nowMin != null) {
                var step = o.slotMinutes || 15;
                var next = Math.ceil((o.nowMin + (o.leadMinutes || 0)) / step) * step;

                if (next > m) { m = next; }
            }

            return m;
        },

        /** Offset of a time zone from UTC in minutes at an instant (Intl; no tz database of our own). */
        tzOffset: function (tz, ms) {
            var f = new Intl.DateTimeFormat('en-US', {
                timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
            });
            var p = {};

            f.formatToParts(new Date(ms)).forEach(function (x) { p[x.type] = x.value; });

            return (Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second) - Math.floor(ms / 1000) * 1000) / 60000;
        },

        /** Local wall-clock (date + minutes from midnight) in `tz` -> UTC "YYYY-MM-DD HH:MM:SS" (what an Espo datetime attribute holds). */
        localToUtc: function (date, minutes, tz) {
            var wall = parse(date) + minutes * 60000;
            var ms = wall - api.tzOffset(tz, wall) * 60000;

            // the offset can differ at the corrected instant (the hour of a clock change): settle once more
            ms = wall - api.tzOffset(tz, ms) * 60000;

            var d = new Date(ms);

            return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) + ' ' +
                pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds());
        },

        /** UTC bounds [from, to) of the LOCAL days from..to (inclusive) - the same pattern the report endpoints use. */
        utcBounds: function (from, to, tz) {
            return {from: api.localToUtc(from, 0, tz), to: api.localToUtc(api.addDays(to, 1), 0, tz)};
        },

        hm: function (min) { return pad(Math.floor(min / 60)) + ':' + pad(min % 60); },

        /** The orders of one day, keyed by the hour they start in, in time order. */
        ordersByHour: function (orders, date) {
            var out = {};

            (orders || []).filter(function (o) { return o.date === date; }).sort(function (a, b) { return a.startMin - b.startMin; }).forEach(function (o) {
                (out[Math.floor(o.startMin / 60)] = out[Math.floor(o.startMin / 60)] || []).push(o);
            });

            return out;
        },

        /** Hours to draw for a set of days: from the earliest opening to the latest closing of the OPEN days (null when all closed). */
        hourSpan: function (days) {
            var lo = null, hi = null;

            (days || []).forEach(function (d) {
                if (d.closed || d.open == null) { return; }

                var a = Math.floor(d.open / 60), b = Math.ceil(d.close / 60);

                lo = lo == null ? a : Math.min(lo, a);
                hi = hi == null ? b : Math.max(hi, b);
            });

            return lo == null ? null : {from: lo, to: hi};
        },

        /** The hour record of a day for an hour, or null (closed hour / outside opening). */
        hourOf: function (day, h) {
            var r = null;

            ((day && day.hours) || []).forEach(function (x) { if (x.h === h) { r = x; } });

            return r;
        },

        /** Order chips: which CSS class and whether a flag is shown. */
        chipClass: function (status) {
            return {'Reserved': 'reserved', 'New': 'wait', 'In Progress': 'progress', 'Completed': 'done'}[status] || 'cancel';
        },

        /** Totals for a set of days (the strip under the toolbar). */
        totals: function (days) {
            var t = {cars: 0, booked: 0, capacity: 0, over: 0};

            (days || []).forEach(function (d) {
                t.cars += d.cars || 0;
                t.booked += d.bookedMinutes || 0;
                t.capacity += d.capacityMinutes || 0;
                t.over += d.overrides || 0;
            });
            t.pct = t.capacity > 0 ? Math.round(100 * t.booked / t.capacity) : 0;

            return t;
        },
    };

    return api;
});
