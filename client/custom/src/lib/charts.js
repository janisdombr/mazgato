/**
 * Dependency-free inline-SVG charts (R3). Every function returns an HTML string; colours
 * come from CSS classes (.cw-chart-1 … set `color`, shapes use `currentColor`), so a theme
 * switch needs no redraw and no hex ever lives in JS. Each chart carries <title>/<desc>
 * for assistive tech and is always paired with a table by the caller.
 * Pure functions — covered by tests/client.test.js in Node.
 */
define(function () {

    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function num(n) { return Math.round(n * 100) / 100; }

    function a11y(title, desc) {
        return '<title>' + esc(title) + '</title><desc>' + esc(desc || title) + '</desc>';
    }

    var api = {

        esc: esc,

        /**
         * Change against the previous period. null when there is nothing to compare with
         * (previous = 0): "+∞ %" would be noise, not information.
         */
        delta: function (cur, prev) {
            cur = Number(cur) || 0;
            prev = Number(prev) || 0;

            if (prev === 0) { return null; }

            var pct = Math.round((cur - prev) * 1000 / Math.abs(prev)) / 10;

            return {pct: pct, dir: pct > 0 ? 'up' : (pct < 0 ? 'down' : 'flat')};
        },

        /** "+12,5 %" / "−3 %" / "0 %" (decimal comma for lv/ru). */
        fmtDelta: function (d, lang) {
            if (!d) { return ''; }

            var s = String(Math.abs(d.pct));

            if (lang !== 'en_US') { s = s.replace('.', ','); }

            return (d.pct > 0 ? '+' : (d.pct < 0 ? '−' : '')) + s + ' %';
        },

        /** Tiny trend line. values: numbers; returns '' for < 2 points. */
        sparkline: function (values, opts) {
            opts = opts || {};
            var w = opts.w || 120, h = opts.h || 32, pad = 3;

            if (!values || values.length < 2) { return ''; }

            var max = Math.max.apply(null, values), min = Math.min.apply(null, values);
            var span = max - min || 1;
            var step = (w - pad * 2) / (values.length - 1);
            var pts = values.map(function (v, i) {
                return num(pad + i * step) + ',' + num(h - pad - (v - min) * (h - pad * 2) / span);
            });
            var last = pts[pts.length - 1].split(',');

            return '<svg class="cw-chart cw-spark ' + esc(opts.cls || 'cw-chart-1') + '" viewBox="0 0 ' + w + ' ' + h + '" role="img" preserveAspectRatio="none">' +
                a11y(opts.title || 'Trend', opts.desc) +
                '<polyline points="' + pts.join(' ') + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>' +
                '<circle cx="' + last[0] + '" cy="' + last[1] + '" r="2.5" fill="currentColor"/></svg>';
        },

        /**
         * Vertical bars. items: [{label, value, text}]. Rounded tops, the maximum is labelled
         * directly (no legend needed for one series). opts: {title, desc, h, cls, maxText}.
         */
        bars: function (items, opts) {
            opts = opts || {};
            var n = items.length;

            if (!n) { return ''; }

            var w = 600, h = opts.h || 160, top = 18, bottom = 18;
            var max = Math.max.apply(null, items.map(function (i) { return i.value; })) || 1;
            var slot = w / n, bw = Math.max(2, Math.min(28, slot * 0.7));
            var maxIdx = items.reduce(function (m, it, i) { return it.value > items[m].value ? i : m; }, 0);
            var ph = h - top - bottom;
            var out = '';

            items.forEach(function (it, i) {
                var bh = it.value > 0 ? Math.max(2, it.value * ph / max) : 0;
                var x = i * slot + (slot - bw) / 2, y = top + ph - bh;

                if (bh) {
                    out += '<rect x="' + num(x) + '" y="' + num(y) + '" width="' + num(bw) + '" height="' + num(bh) + '" rx="' + num(Math.min(4, bw / 2)) + '" fill="currentColor"><title>' + esc(it.label + ': ' + (it.text != null ? it.text : it.value)) + '</title></rect>';
                }
            });

            // axis labels: first, middle, last
            [0, Math.floor((n - 1) / 2), n - 1].filter(function (v, i, a) { return a.indexOf(v) === i; }).forEach(function (i) {
                var anchor = i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle');
                var x = i === 0 ? 0 : (i === n - 1 ? w : i * slot + slot / 2);
                out += '<text class="cw-chart-axis" x="' + num(x) + '" y="' + (h - 4) + '" text-anchor="' + anchor + '">' + esc(items[i].label) + '</text>';
            });

            if (items[maxIdx].value > 0) {
                var mx = maxIdx * slot + slot / 2;
                var anchorM = mx > w - 60 ? 'end' : (mx < 60 ? 'start' : 'middle');
                out += '<text class="cw-chart-label" x="' + num(anchorM === 'end' ? Math.min(w, mx + bw / 2) : (anchorM === 'start' ? Math.max(0, mx - bw / 2) : mx)) + '" y="11" text-anchor="' + anchorM + '">' + esc(opts.maxText != null ? opts.maxText : items[maxIdx].value) + '</text>';
            }

            return '<svg class="cw-chart ' + esc(opts.cls || 'cw-chart-1') + '" viewBox="0 0 ' + w + ' ' + h + '" role="img">' +
                a11y(opts.title || 'Chart', opts.desc) +
                '<line class="cw-chart-grid" x1="0" x2="' + w + '" y1="' + (top + ph) + '" y2="' + (top + ph) + '"/>' + out + '</svg>';
        },

        /**
         * Horizontal bars with the value written at the end of each. items: [{label, value, text}].
         */
        hbars: function (items, opts) {
            opts = opts || {};

            if (!items.length) { return ''; }

            var rowH = 28, w = 600, labelW = 170, valueW = 90;
            var max = Math.max.apply(null, items.map(function (i) { return i.value; })) || 1;
            var h = items.length * rowH;
            var barMax = w - labelW - valueW;
            var out = '';

            items.forEach(function (it, i) {
                var y = i * rowH, bw = it.value > 0 ? Math.max(2, it.value * barMax / max) : 0;
                var label = it.label.length > 24 ? it.label.slice(0, 23) + '…' : it.label;

                out += '<text class="cw-chart-label" x="0" y="' + (y + 18) + '">' + esc(label) + '<title>' + esc(it.label) + '</title></text>' +
                    '<rect x="' + labelW + '" y="' + (y + 5) + '" width="' + num(bw) + '" height="' + (rowH - 10) + '" rx="4" fill="currentColor"/>' +
                    '<text class="cw-chart-value" x="' + num(labelW + bw + 6) + '" y="' + (y + 18) + '">' + esc(it.text != null ? it.text : it.value) + '</text>';
            });

            return '<svg class="cw-chart ' + esc(opts.cls || 'cw-chart-1') + '" viewBox="0 0 ' + w + ' ' + h + '" role="img">' +
                a11y(opts.title || 'Chart', opts.desc) + out + '</svg>';
        },

        /**
         * Donut of at most `max` slices (default 5) + "other"; fixed colour order, never cycling.
         * Returns {svg, legend} so the caller places the legend (with amounts) beside it.
         */
        donut: function (items, opts) {
            opts = opts || {};
            var max = opts.max || 5;
            var sorted = items.slice().sort(function (a, b) { return b.value - a.value; });
            var head = sorted.slice(0, max);
            var rest = sorted.slice(max);

            if (rest.length) {
                head.push({label: opts.otherLabel || '…', value: rest.reduce(function (s, i) { return s + i.value; }, 0), other: true});
            }

            var total = head.reduce(function (s, i) { return s + i.value; }, 0);

            if (!total) { return {svg: '', legend: []}; }

            var r = 42, c = 2 * Math.PI * r, off = 0, out = '';

            head.forEach(function (it, i) {
                var len = it.value * c / total;
                var cls = it.other ? 'cw-chart-other' : 'cw-chart-' + (i + 1);

                it.cls = cls;
                it.pct = Math.round(it.value * 1000 / total) / 10;
                out += '<circle class="' + cls + '" cx="60" cy="60" r="' + r + '" fill="none" stroke="currentColor" stroke-width="18" ' +
                    'stroke-dasharray="' + num(len) + ' ' + num(c - len) + '" stroke-dashoffset="' + num(-off) + '" transform="rotate(-90 60 60)"><title>' + esc(it.label + ': ' + it.pct + ' %') + '</title></circle>';
                off += len;
            });

            return {
                svg: '<svg class="cw-chart cw-donut" viewBox="0 0 120 120" role="img">' + a11y(opts.title || 'Share', opts.desc) +
                    '<circle class="cw-chart-track" cx="60" cy="60" r="' + r + '" fill="none" stroke="currentColor" stroke-width="18"/>' + out + '</svg>',
                legend: head,
            };
        },

        /**
         * 24-cell heat strip (one tone, 5 steps from the count). counts: array of 24 numbers.
         * opts: {title, desc, hourLabel(h)}.
         */
        heatHours: function (counts, opts) {
            opts = opts || {};
            var max = Math.max.apply(null, counts) || 0;

            if (!max) { return ''; }

            var w = 600, cw = w / 24, out = '';

            counts.forEach(function (n, h) {
                var step = n === 0 ? 0 : Math.min(5, Math.max(1, Math.ceil(n * 5 / max)));

                out += '<rect class="cw-heat-' + step + '" x="' + num(h * cw + 1) + '" y="0" width="' + num(cw - 2) + '" height="34" rx="4" fill="currentColor"><title>' +
                    esc((opts.hourLabel ? opts.hourLabel(h) : (h + ':00')) + ': ' + n) + '</title></rect>';
            });

            [0, 6, 12, 18, 23].forEach(function (h) {
                out += '<text class="cw-chart-axis" x="' + num(h * cw + cw / 2) + '" y="50" text-anchor="' + (h === 0 ? 'start' : (h === 23 ? 'end' : 'middle')) + '">' + (h < 10 ? '0' : '') + h + '</text>';
            });

            return '<svg class="cw-chart cw-heat" viewBox="0 0 ' + w + ' 54" role="img">' + a11y(opts.title || 'Load by hour', opts.desc) + out + '</svg>';
        },
    };

    return api;
});
