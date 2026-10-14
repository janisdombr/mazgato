/**
 * Order detail with a header (D3/D28): plate, the two status chips, the TOTAL at 32 px and
 * the three things done at the counter — Paid / Start or Done / Print — before the form,
 * instead of the price living 1000 px down in the "Services" panel.
 *
 * Rules are the board's (CLAUDE.md "Independent flags"): Paid only for cash/card, an invoice
 * is not a payment, work status and payment are separate.
 */
define(['views/record/detail', 'custom:lib/format', 'custom:lib/state'], function (Dep, Fmt, State) {

    var STATUS = {
        'Reserved': ['reserved', 'fa-calendar-check'], 'New': ['wait', 'fa-hourglass-half'],
        'In Progress': ['progress', 'fa-droplet'], 'Completed': ['done', 'fa-check'], 'Cancelled': ['cancel', 'fa-ban'],
    };
    var PAY = {'Unpaid': ['unpaid', 'fa-euro-sign'], 'Invoiced': ['invoiced', 'fa-file-invoice'], 'Paid': ['paid', 'fa-check']};

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.listenTo(this.model, 'sync change:status change:paymentStatus change:finalPrice change:loyaltyDiscount change:loyaltyNote change:pointsRedeemed change:pointsEarned change:pointsNote change:referralNote', function () {
                if (this.isRendered()) { this.renderHead(); }
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (!this.$el.find('.cw-order-head').length) {
                this.$el.prepend('<div class="cw-order-head"></div>');
            }

            this.renderHead();
            this.hideLoyaltyWhenOff();
        },

        // Module `loyalty` off (or unreachable): the order form shows no promo / loyalty fields at all.
        hideLoyaltyWhenOff: function () {
            if (this.loyaltyChecked) { return; }

            this.loyaltyChecked = true;
            Espo.Ajax.postRequest('Loyalty/action/status', {}).then(function (r) {
                if (r && r.enabled) { return; }

                ['promoCode', 'skipLoyalty', 'loyaltyKind', 'loyaltyDiscount', 'loyaltyNote', 'redeemPoints', 'pointsRedeemed', 'pointsDiscount', 'pointsEarned', 'pointsNote', 'referralCode', 'referralNote'].forEach(function (f) { this.hideField(f); }, this);
                var label = this.translate('loyaltyTab', 'labels', 'WashOrder');

                this.$el.find('.nav-tabs li, .nav-tabs a').filter(function () { return $.trim($(this).text()) === label; }).prop('hidden', true);
            }.bind(this)).catch(function (xhr) {
                if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
            });
        },

        l: function (key, scope) { return this.translate(key, 'labels', scope || 'TodayOrders'); },

        chip: function (map, value, text) {
            var m = map[value] || ['cancel', 'fa-circle'];

            return '<span class="cw-chip cw-chip--' + m[0] + '"><i class="fas ' + m[1] + '" aria-hidden="true"></i>' + State.esc(text) + '</span>';
        },

        opt: function (field, value) {
            try { return this.getLanguage().translateOption(value, field, 'WashOrder'); } catch (e) { return value; }
        },

        renderHead: function () {
            var m = this.model;
            var status = m.get('status');
            var pay = m.get('paymentStatus');
            var counter = m.get('paymentMethod') !== 'Invoice';
            var lang = this.getPreferences().get('language') || this.getConfig().get('language');
            var btns = '';

            if (pay !== 'Paid' && counter) {
                btns += '<button type="button" class="cw-btn cw-btn--pay" data-act="paid"><i class="fas fa-euro-sign"></i>' + State.esc(this.l('markPaid')) + '</button>';
            }
            if (status === 'New' || status === 'Reserved') {
                btns += '<button type="button" class="cw-btn cw-btn--primary" data-act="start"><i class="fas fa-play"></i>' + State.esc(this.l('start')) + '</button>';
            }
            if (status === 'In Progress') {
                btns += '<button type="button" class="cw-btn cw-btn--done" data-act="done"><i class="fas fa-check"></i>' + State.esc(this.l('done')) + '</button>';
            }
            var temp = !!m.get('vehiclePlateTemporary') && !!m.get('vehicleId');
            if (temp && this.getAcl().check('Vehicle', 'edit')) {
                btns += '<button type="button" class="cw-btn" data-act="realPlate"><i class="fas fa-pen"></i>' + State.esc(this.translate('setRealPlate', 'labels', 'Vehicle')) + '</button>';
            }
            btns += '<button type="button" class="cw-btn" data-act="print"><i class="fas fa-print"></i>' + State.esc(this.translate('Print to PDF', 'labels', 'Global')) + '</button>';

            var payChip = pay === 'Unpaid' && status !== 'Completed'
                ? '<span class="cw-chip cw-chip--pending"><i class="fas fa-clock" aria-hidden="true"></i>' + State.esc(this.l('payAwaiting')) + '</span>'
                : this.chip(PAY, pay, this.opt('paymentStatus', pay));

            this.$el.find('.cw-order-head').html(
                '<div class="cw-order-head__id">' +
                    '<span class="cw-plate">' + State.esc(m.get('vehicleName') || m.get('name') || '—') + '</span>' +
                    (temp ? ' <span class="cw-chip cw-chip--wait">' + State.esc(this.l('tempPlate')) + '</span>' : '') +
                    '<div class="cw-order-head__chips">' + this.chip(STATUS, status, this.opt('status', status)) + payChip + '</div>' +
                    '<div class="cw-meta">' + State.esc([m.get('customerName'), m.get('serviceName')].filter(Boolean).join(' · ')) + '</div>' +
                '</div>' +
                '<div class="cw-order-head__sum"><div class="cw-meta">' + State.esc(this.translate('finalPrice', 'fields', 'WashOrder')) + '</div>' +
                    '<div class="cw-hero cw-num">' + State.esc(Fmt.fmtMoney(m.get('finalPrice'), lang) || '—') + '</div>' +
                    this.loyaltyLine(lang) + '</div>' +
                '<div class="cw-actions">' + btns + '</div>'
            );

            this.$el.find('.cw-order-head [data-act]').off('click').on('click', function (e) {
                this.headAction($(e.currentTarget));
            }.bind(this));
        },

        // What the loyalty rules did to this order: the discount taken off, or why a promo code did not apply.
        loyaltyLine: function (lang) {
            var m = this.model;
            var L = function (k, sec) { return this.translate(k, sec || 'labels', 'Loyalty'); }.bind(this);
            var off = parseFloat(m.get('loyaltyDiscount')) || 0;
            var note = m.get('loyaltyNote');
            var out = '';

            if (off > 0 && m.get('loyaltyKind')) {
                out += '<div class="cw-meta">' + State.esc(L('orderApplied')
                    .replace('{kind}', L(m.get('loyaltyKind'), 'kinds')).replace('{amount}', Fmt.fmtMoney(off, lang))) + '</div>';
            }
            if (note) {
                out += '<div class="cw-meta">' + State.esc(L('orderNote').replace('{note}', L(note, 'notes'))) + '</div>';
            }

            // points: what this order spent and what it earned, or why the request was not honoured
            var spent = parseInt(m.get('pointsRedeemed'), 10) || 0;
            var earned = parseInt(m.get('pointsEarned'), 10) || 0;
            var pnote = m.get('pointsNote');
            var rnote = m.get('referralNote');

            if (spent > 0) {
                out += '<div class="cw-meta">' + State.esc(L('orderPoints').replace('{n}', spent).replace('{amount}', Fmt.fmtMoney(parseFloat(m.get('pointsDiscount')) || 0, lang))) + '</div>';
            }
            if (earned > 0) {
                out += '<div class="cw-meta">' + State.esc(L('orderEarned').replace('{n}', earned)) + '</div>';
            }
            if (pnote) {
                out += '<div class="cw-meta">' + State.esc(L('pointsRefused').replace('{reason}', L(pnote, 'pointsNotes'))) + '</div>';
            }
            if (rnote) {
                out += '<div class="cw-meta">' + State.esc(L('orderReferral').replace('{note}', L(rnote, 'referralReasons'))) + '</div>';
            }

            return out;
        },

        headAction: function ($b) {
            var act = $b.data('act');

            if (act === 'realPlate') {
                this.createView('realPlate', 'custom:views/vehicle/modals/set-real-plate', {
                    vehicleId: this.model.get('vehicleId'),
                    onDone: function () { this.model.fetch(); }.bind(this),
                }, function (v) { v.render(); });
                return;
            }

            if (act === 'print') {
                if (this.actionPrintPdf) { this.actionPrintPdf(); }
                return;
            }

            var patch = {paid: {paymentStatus: 'Paid'}, start: {status: 'In Progress'}, done: {status: 'Completed'}}[act];

            if (!patch || $b.hasClass('is-busy')) { return; }

            $b.addClass('is-busy');

            this.model.save(patch, {patch: true}).then(function () {
                Espo.Ui.success(this.translate('Saved', 'labels', 'Global'));
            }.bind(this)).catch(function () { $b.removeClass('is-busy'); });
        },
    });
});
