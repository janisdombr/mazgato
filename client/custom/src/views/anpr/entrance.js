/**
 * "At the entrance" - cars the plate camera has seen and a person has not yet decided about.
 * Embedded at the top of the wash board (TodayOrders) and available as the Gate dashlet.
 *
 * Polls Anpr/action/pending every `pollSeconds` (paused while the tab is hidden), draws one card per
 * pending event (thumbnail, plate large, confidence, customer / suggestion, last services) with
 * 48 px Confirm / Correct / Dismiss. A NEW card flashes and, when the setting and the operator's
 * own toggle allow, beeps. Hidden entirely when the module is off or the role has no access.
 * All rules (low confidence = a person decides, 409 for a car that is already on the board) are the
 * server's; this view explains its answers. See lib/gate.js for the logic.
 */
define(['view', 'custom:lib/gate', 'custom:lib/state', 'custom:lib/lightbox'], function (View, Gate, State, Lightbox) {

    var SOUND_KEY = 'cwGateSound';

    return View.extend({

        templateContent: '<div class="cw-gate-root"></div>',

        events: {
            'click .gate-zoom': function (e) { var $b = $(e.currentTarget); Lightbox.open($b.data('src'), $b.data('cap'), this.t('close')); },
            'click .gate-correct': function (e) { this.toggleEdit($(e.currentTarget)); },
            'click .gate-use': function (e) { this.useSuggestion($(e.currentTarget)); },
            'click .gate-confirm': function (e) { this.confirm($(e.currentTarget), false); },
            'click .gate-attach': function (e) { this.confirm($(e.currentTarget), true); },
            'click .gate-dismiss': function (e) { this.dismiss($(e.currentTarget)); },
            'change .gate-service': function (e) { var c = this.cardOf($(e.currentTarget)); this.uiOf(c.id).serviceId = $(e.currentTarget).val(); },
            'input .gate-name': function (e) { var c = this.cardOf($(e.currentTarget)); this.uiOf(c.id).customerName = $(e.currentTarget).val(); },
            'input .gate-plate': function (e) { var c = this.cardOf($(e.currentTarget)); this.uiOf(c.id).plate = $(e.currentTarget).val(); },
            'click .gate-sound': function () { this.toggleSound(); },
        },

        setup: function () {
            this.ui = {};
            this.sig = null;
            this.prevIds = null;
            this.services = null;
            this.cfg = null;
            this.cards = [];
            this.enabled = null;
            this.once('remove', this.stop.bind(this));
        },

        t: function (key) { return this.translate(key, 'labels', 'Anpr'); },

        errText: function (code) {
            var t = code ? this.translate(code, 'errors', 'Anpr') : '';
            return t && t !== code ? t : this.translate('error', 'labels', 'Anpr');
        },

        root: function () { return this.$el.find('.cw-gate-root'); },

        afterRender: function () {
            this.stop();
            Espo.Ajax.postRequest('Anpr/action/status', {}).then(function (st) {
                this.enabled = !!st.enabled;
                if (!this.enabled) { this.root().empty(); return; }
                this.status = st;
                this.loadServices();
                this.load();
                this.startPolling(st.pollSeconds || 5);
            }.bind(this)).catch(function () { this.root().empty(); }.bind(this));
        },

        startPolling: function (seconds) {
            this.timer = setInterval(function () {
                if (!document.hidden) { this.load(true); }
            }.bind(this), Math.max(2, seconds) * 1000);
        },

        stop: function () { if (this.timer) { clearInterval(this.timer); this.timer = null; } },

        loadServices: function () {
            Espo.Ajax.getRequest('WashService', {
                maxSize: 100, orderBy: 'name', order: 'asc',
                where: [{type: 'equals', attribute: 'category', value: 'Base'}],
            }).then(function (d) {
                this.services = (d.list || []).map(function (s) { return {id: s.id, name: s.name}; });
                this.repaint(true);
            }.bind(this)).catch(function () { this.services = []; }.bind(this));
        },

        load: function (quiet) {
            Espo.Ajax.postRequest('Anpr/action/pending', {}).then(function (d) {
                this.cfg = d;
                var list = d.list || [];
                var fresh = Gate.newIds(this.prevIds, list);
                this.prevIds = list.map(function (c) { return c.id; });
                this.cards = list;
                this.fresh = fresh;
                var sig = Gate.signature(list) + '#' + (this.services ? this.services.length : 0);

                if (sig !== this.sig) { this.sig = sig; this.repaint(); }
                if (fresh.length) { this.alert(); }
            }.bind(this)).catch(function (xhr) {
                if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
                if (!quiet) { this.root().html(State.error({title: this.translate('loadError', 'labels', 'Anpr'), retryLabel: this.translate('retry', 'labels', 'Anpr')})); }
            }.bind(this));
        },

        uiOf: function (id) { return this.ui[id] || (this.ui[id] = {}); },

        cardOf: function ($el) {
            var id = $el.closest('.cw-gcard').data('id');
            return this.cards.filter(function (c) { return c.id === id; })[0] || {id: id};
        },

        fmtTime: function (v) {
            if (!v) { return ''; }
            try { var dt = this.getDateTime(); return dt.toMoment(v).format(dt.timeFormat); } catch (e) { return String(v).slice(11, 16); }
        },

        soundOn: function () {
            var mine = window.localStorage ? window.localStorage.getItem(SOUND_KEY) : null;
            return !!(this.cfg && this.cfg.alertSound) && mine !== 'off';
        },

        toggleSound: function () {
            if (window.localStorage) { window.localStorage.setItem(SOUND_KEY, this.soundOn() ? 'off' : 'on'); }
            this.repaint(true);
        },

        repaint: function () {
            if (!this.enabled || !this.cfg) { return; }
            var d = this.cfg;
            var canDecide = !!(this.status && this.status.canDecide);
            var h = '<section class="cw-gate" aria-label="' + State.esc(this.t('gateTitle')) + '"><div class="cw-gate__head"><h3 class="cw-gate__title"><i class="fas fa-car-side" aria-hidden="true"></i> ' +
                State.esc(this.t('gateTitle')) + (this.cards.length ? ' <span class="cw-chip cw-chip--wait">' + this.cards.length + '</span>' : '') + '</h3>' +
                '<button type="button" class="cw-btn gate-sound" aria-pressed="' + (this.soundOn() ? 'true' : 'false') + '"><i class="fas ' + (this.soundOn() ? 'fa-volume-high' : 'fa-volume-xmark') + '" aria-hidden="true"></i> ' +
                State.esc(this.t(this.soundOn() ? 'soundOn' : 'soundOff')) + '</button></div>';

            if (!this.cards.length) {
                h += '<div class="cw-gate__empty">' + State.esc(this.t('gateEmpty')) + '</div>';
            } else {
                h += '<div class="cw-gate__list">' + this.cards.map(function (c) {
                    return Gate.cardHtml(c, {
                        t: this.t.bind(this), fmtTime: this.fmtTime.bind(this), services: this.services || [], canDecide: canDecide,
                        min: d.minConfidence, high: d.highConfidence, ui: this.ui[c.id], isNew: (this.fresh || []).indexOf(c.id) >= 0,
                    });
                }.bind(this)).join('') + '</div>';
            }

            this.root().html(h + '</section>');
            this.fresh = [];
        },

        alert: function () {
            if (!this.soundOn()) { return; }
            try {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) { return; }
                this.audio = this.audio || new Ctx();
                var o = this.audio.createOscillator(), g = this.audio.createGain();
                o.type = 'sine'; o.frequency.value = 880; g.gain.value = 0.08;
                o.connect(g); g.connect(this.audio.destination);
                o.start(); o.stop(this.audio.currentTime + 0.18);
            } catch (e) { /* the beep is a convenience: autoplay rules may forbid it */ }
        },

        toggleEdit: function ($btn) {
            var c = this.cardOf($btn), u = this.uiOf(c.id);
            u.editing = !u.editing;
            var $card = $btn.closest('.cw-gcard');
            $card.find('.gate-edit').prop('hidden', !u.editing);
            if (u.editing) { $card.find('.gate-plate').trigger('focus').trigger('select'); }
        },

        useSuggestion: function ($btn) {
            var c = this.cardOf($btn), u = this.uiOf(c.id);
            u.editing = true; u.plate = $btn.data('plate');
            this.repaint();
        },

        busy: function ($btn, on) { $btn.closest('.cw-gcard').find('button').prop('disabled', on); },

        confirm: function ($btn, attach) {
            var c = this.cardOf($btn), u = this.uiOf(c.id);
            u.error = null;
            this.busy($btn, true);

            Espo.Ajax.postRequest('Anpr/action/confirm', Gate.confirmBody(c, u, attach)).then(function (res) {
                Espo.Ui.success(this.t(res.attached ? 'attached' : 'orderCreated') + (res.orderName ? ': ' + res.orderName : ''));
                delete this.ui[c.id];
                this.load(true);
                // the board below shows the new order at once
                try { this.getParentView() && this.getParentView().actionRefresh && this.getParentView().actionRefresh(); } catch (e) { /* optional */ }
            }.bind(this)).catch(function (xhr) {
                if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
                var code = Gate.errorCode(xhr);
                u.error = this.errText(code);
                u.attach = code === 'active_order';
                if (code === 'already_decided') { delete this.ui[c.id]; this.load(true); return; }
                this.repaint();
            }.bind(this));
        },

        dismiss: function ($btn) {
            var c = this.cardOf($btn);
            this.busy($btn, true);
            Espo.Ajax.postRequest('Anpr/action/dismiss', {eventId: c.id}).then(function () {
                Espo.Ui.success(this.t('dismissedMsg'));
                delete this.ui[c.id];
                this.load(true);
            }.bind(this)).catch(function (xhr) {
                if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
                this.uiOf(c.id).error = this.errText(Gate.errorCode(xhr));
                this.repaint();
            }.bind(this));
        },
    });
});
