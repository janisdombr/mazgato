/**
 * "Before / after photos" on an order: the gate camera's Entry and Exit pictures, and the worker's
 * Before and After pictures, side by side or on a slider, each opening large. Every other picture
 * of the order is a thumbnail strip underneath. Camera pictures are fetched through `cwMedia`
 * (ACL + access journal). The stock Photos panel below still lists and edits the records.
 */
define(['views/record/panels/bottom', 'custom:lib/photopair', 'custom:lib/state', 'custom:lib/lightbox'], function (Dep, Pair, State, Lightbox) {

    var esc = State.esc;

    return Dep.extend({

        templateContent: '<div class="cw-cmp cmp-root"></div>',

        events: {
            'click .cmp-tab': function (e) { this.mode = $(e.currentTarget).data('mode'); this.paint(); },
            'click .cmp-view': function (e) { this.slider = $(e.currentTarget).data('view') === 'slider'; this.paint(); },
            'click .cmp-zoom': function (e) { var $i = $(e.currentTarget); Lightbox.open($i.data('src'), $i.data('cap'), this.t('close')); },
            'input .cmp-range': function (e) { this.$el.find('.cw-cmp__slider').get(0).style.setProperty('--cw-pos', $(e.currentTarget).val() + '%'); },
        },

        t: function (k) { return this.translate(k, 'labels', 'Photos'); },

        setup: function () {
            Dep.prototype.setup.call(this);
            this.listenTo(this.model, 'sync after:save', function () { this.load(); }, this);
        },

        afterRender: function () { this.load(); },

        load: function () {
            if (!this.model.id) { return; }
            var $r = this.$el.find('.cmp-root');
            if (!this.photos) { $r.html(State.loading(this.translate('loading', 'labels', 'CarWash'))); }

            Espo.Ajax.getRequest('OrderPhoto', {
                maxSize: 60, orderBy: 'takenAt', order: 'asc',
                where: [{type: 'equals', attribute: 'washOrderId', value: this.model.id}],
            }).then(function (d) {
                this.photos = d.list || [];
                this.paint();
            }.bind(this)).catch(function (xhr) {
                if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
                $r.html('<div class="cw-meta">' + esc(this.t('loadError')) + '</div>');
            }.bind(this));
        },

        fmt: function (p) {
            if (!p || !p.takenAt) { return ''; }
            try { return this.getDateTime().toDisplay(p.takenAt); } catch (e) { return p.takenAt; }
        },

        cell: function (p, tag) {
            var u = Pair.url(p);
            var cap = tag + (p ? ' · ' + this.fmt(p) : '');

            if (!u) { return '<div class="cw-cmp__cell"><div class="cw-cmp__empty">' + esc(this.t('missing')) + '</div><span class="cw-meta">' + esc(tag) + '</span></div>'; }

            return '<div class="cw-cmp__cell"><img class="cw-cmp__img cmp-zoom" alt="' + esc(tag) + '" src="' + esc(u) + '" data-src="' + esc(u) + '" data-cap="' + esc(cap) + '"><span class="cw-meta">' + esc(cap) + '</span></div>';
        },

        paint: function () {
            var $r = this.$el.find('.cmp-root');
            var pairs = Pair.pairs(this.photos);
            var have = {entryExit: !!pairs.entryExit, beforeAfter: !!pairs.beforeAfter};

            if (!have.entryExit && !have.beforeAfter) {
                $r.html('<div class="cw-meta">' + esc(this.t('empty')) + '</div>');
                return;
            }

            if (!this.mode || !have[this.mode]) { this.mode = have.entryExit ? 'entryExit' : 'beforeAfter'; }

            var pair = pairs[this.mode];
            var l = this.mode === 'entryExit' ? [this.t('entry'), this.t('exit')] : [this.t('before'), this.t('after')];
            var h = '<div class="cw-cmp__tabs" role="tablist">';

            [['entryExit', 'entryExit'], ['beforeAfter', 'beforeAfter']].forEach(function (m) {
                if (have[m[0]]) { h += '<button type="button" role="tab" aria-selected="' + (this.mode === m[0]) + '" class="cw-btn cmp-tab' + (this.mode === m[0] ? ' cw-btn--primary' : '') + '" data-mode="' + m[0] + '">' + esc(this.t(m[1])) + '</button>'; }
            }, this);

            var both = pair.from && pair.to && Pair.url(pair.from) && Pair.url(pair.to);

            if (both) {
                h += '<button type="button" class="cw-btn cmp-view' + (this.slider ? '' : ' cw-btn--primary') + '" data-view="side">' + esc(this.t('sideBySide')) + '</button>' +
                    '<button type="button" class="cw-btn cmp-view' + (this.slider ? ' cw-btn--primary' : '') + '" data-view="slider">' + esc(this.t('slider')) + '</button>';
            }
            h += '</div>';

            if (both && this.slider) {
                h += '<div class="cw-cmp__slider"><img alt="' + esc(l[1]) + '" src="' + esc(Pair.url(pair.to)) + '"><img class="cw-cmp__top" alt="' + esc(l[0]) + '" src="' + esc(Pair.url(pair.from)) + '">' +
                    '<span class="cw-cmp__tag cw-cmp__tag--l">' + esc(l[0]) + '</span><span class="cw-cmp__tag cw-cmp__tag--r">' + esc(l[1]) + '</span><span class="cw-cmp__handle"></span></div>' +
                    '<input type="range" class="cw-cmp__range cmp-range" min="0" max="100" value="50" aria-label="' + esc(this.t('sliderLabel')) + '">';
            } else {
                h += '<div class="cw-cmp__pair">' + this.cell(pair.from, l[0]) + this.cell(pair.to, l[1]) + '</div>';
            }

            var rest = (this.photos || []).filter(function (p) { return p !== pair.from && p !== pair.to && Pair.url(p); });

            if (rest.length) {
                h += '<div class="cw-meta">' + esc(this.t('allPhotos')) + '</div><div class="cw-cmp__all">' + rest.map(function (p) {
                    var u = Pair.url(p);
                    return '<button type="button" class="cmp-zoom" data-src="' + esc(u) + '" data-cap="' + esc((p.kind || '') + ' · ' + this.fmt(p)) + '"><img alt="' + esc(p.kind) + '" loading="lazy" src="' + esc(u) + '"></button>';
                }, this).join('') + '</div>';
            }

            $r.html(h);
            this.$el.find('.cw-cmp__slider').each(function () { this.style.setProperty('--cw-pos', '50%'); });
        },
    });
});
