/**
 * The "at the entrance" block's logic and markup, free of any framework so tests/anpr.test.js can run
 * it in plain Node. The view (views/anpr/entrance.js) only fetches, paints and wires clicks.
 *
 * Rules shown to the operator (the server enforces them, the UI only explains):
 *   - a card is a PENDING plate event: a person always decides, low confidence is flagged;
 *   - known car -> its customer and last services; near miss -> "looks like XY1234" with a Use button;
 *   - a car that already has an order -> 409 "active_order" with "add the picture to that order";
 *   - Confirm creates the order through the existing intake; Correct opens the plate field.
 */
define(['custom:lib/state'], function (State) {

    var esc = State.esc;

    function normPlate(s) {
        return String(s == null ? '' : s).replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    }

    /** 'high' | 'mid' | 'low' — the same cut-offs as the server (Settings min/high confidence). */
    function band(conf, min, high) {
        conf = Number(conf) || 0;
        return conf < min ? 'low' : (conf >= high ? 'high' : 'mid');
    }

    function pct(conf) { return Math.round((Number(conf) || 0) * 100) + ' %'; }

    /** ids in `list` that were not in `prevIds` (null prevIds = first load = nothing is "new"). */
    function newIds(prevIds, list) {
        if (!prevIds) { return []; }
        return list.filter(function (c) { return prevIds.indexOf(c.id) < 0; }).map(function (c) { return c.id; });
    }

    /** A signature of what the cards SHOW, so an unchanged poll does not repaint (and reload thumbnails). */
    function signature(list) {
        return list.map(function (c) {
            return [c.id, c.plate, c.confidence, c.known ? 1 : 0, c.suggestedPlate || '', c.activeOrder ? c.activeOrder.id : '', c.seenCount].join('~');
        }).join('|');
    }

    /** The server's refusal code out of a failed Espo.Ajax request (the body is {ok:false,error}). */
    function errorCode(xhr) {
        try {
            var j = xhr && (xhr.responseJSON || JSON.parse(xhr.responseText));
            return j && j.error ? String(j.error) : '';
        } catch (e) { return ''; }
    }

    function orderOf(xhr) {
        try {
            var j = xhr && (xhr.responseJSON || JSON.parse(xhr.responseText));
            return j && j.orderId ? {id: j.orderId, name: j.orderName} : null;
        } catch (e) { return null; }
    }

    /** What to POST on Confirm. `ui` = {plate (edited text or ''), serviceId}. */
    function confirmBody(card, ui, attach) {
        var body = {eventId: card.id};
        var plate = normPlate(ui && ui.plate);

        if (plate && plate !== card.plate) { body.plate = plate; }
        if (ui && ui.serviceId) { body.serviceId = ui.serviceId; }
        // the payer: a car with no customer on file needs at least a name (the server refuses 400 customer_name_required)
        if (ui && ui.customerName && String(ui.customerName).trim()) { body.customerName = String(ui.customerName).trim(); }
        if (attach) { body.attach = true; }

        return body;
    }

    /** The service the select starts on: the operator's pick, else the car's last service. */
    function defaultService(card, ui) {
        if (ui && ui.serviceId) { return ui.serviceId; }
        return card.services && card.services.length ? card.services[0].id : '';
    }

    /**
     * ctx: {t(key), fmtTime(utc), services:[{id,name}], canDecide, min, high, ui:{plate,editing,serviceId,error,attach}, isNew}
     */
    function cardHtml(card, ctx) {
        var t = ctx.t;
        var ui = ctx.ui || {};
        var b = band(card.confidence, ctx.min, ctx.high);
        var chip = b === 'high' ? 'cw-chip--done' : (b === 'mid' ? 'cw-chip--wait' : 'cw-chip--unpaid');
        var svc = defaultService(card, ui);
        var h = '<article class="cw-gcard cw-gcard--' + b + (ctx.isNew ? ' is-new' : '') + '" data-id="' + esc(card.id) + '" aria-label="' + esc(card.plate) + '">';

        h += card.snapshotUrl
            ? '<button type="button" class="cw-gcard__thumb gate-zoom" data-src="' + esc(card.snapshotUrl) + '" data-cap="' + esc(card.plate) + '" aria-label="' + esc(t('enlarge')) + '"><img alt="" loading="lazy" src="' + esc(card.snapshotUrl) + '"></button>'
            : '<div class="cw-gcard__nothumb">' + esc(t('noSnapshot')) + '</div>';

        h += '<div class="cw-gcard__body">';
        h += '<div class="cw-gcard__plate">' + esc(card.plate) + '</div>';
        h += '<div class="cw-gcard__line"><span class="cw-chip ' + chip + '">' + esc(t('confidence')) + ' ' + esc(pct(card.confidence)) + '</span>';
        if (card.camera) { h += '<span class="cw-meta">' + esc(card.camera.name) + '</span>'; }
        h += '<span class="cw-meta">' + esc(ctx.fmtTime(card.takenAt)) + '</span>';
        if (card.seenCount > 1) { h += '<span class="cw-meta">' + esc(t('seenTimes')) + ' ×' + card.seenCount + '</span>'; }
        h += '</div>';

        if (b === 'low') { h += '<div class="cw-gcard__warn">' + esc(t('lowConfidence')) + '</div>'; }

        if (card.matchKind === 'Fuzzy' && card.suggestedPlate) {
            h += '<div class="cw-gcard__hint">' + esc(t('looksLike')) + ' <b>' + esc(card.suggestedPlate) + '</b>' +
                (card.customer ? ' · ' + esc(card.customer.name) : '') +
                ' <button type="button" class="cw-btn gate-use" data-plate="' + esc(card.suggestedPlate) + '">' + esc(t('useSuggestion')) + '</button></div>';
        } else if (card.known && card.customer) {
            h += '<div class="cw-gcard__line"><span class="cw-strong">' + esc(card.customer.name) + '</span></div>';
        } else if (!card.known) {
            h += '<div class="cw-gcard__line"><span class="cw-chip cw-chip--pending">' + esc(t('newCar')) + '</span></div>';
        }

        if (card.activeOrder) {
            h += '<div class="cw-gcard__hint">' + esc(t('activeOrderBadge')) + ': ' + esc(card.activeOrder.name) + '</div>';
        }

        h += '<label class="cw-gcard__edit gate-edit"' + (ui.editing ? '' : ' hidden') + '><span class="cw-meta">' + esc(t('plateInput')) + '</span>' +
            '<input type="text" class="form-control cw-input-plate gate-plate" maxlength="14" autocomplete="off" value="' + esc(ui.plate != null && ui.plate !== '' ? ui.plate : card.plate) + '"></label>';

        if (ctx.canDecide && !(card.known && card.customer)) {
            h += '<label class="cw-gcard__service"><span class="cw-meta">' + esc(t('customerName')) + '</span>' +
                '<input type="text" class="form-control gate-name" maxlength="150" autocomplete="off" placeholder="' + esc(t('customerNameHint')) + '" value="' + esc(ui.customerName || '') + '"></label>';
        }

        if (ctx.canDecide) {
            h += '<label class="cw-gcard__service"><span class="cw-meta">' + esc(t('service')) + '</span><select class="form-control gate-service">';
            h += '<option value="">' + esc(t('chooseService')) + '</option>';
            var seen = {};
            (card.services || []).forEach(function (s) { seen[s.id] = true; h += '<option value="' + esc(s.id) + '"' + (s.id === svc ? ' selected' : '') + '>★ ' + esc(s.name) + '</option>'; });
            (ctx.services || []).forEach(function (s) { if (!seen[s.id]) { h += '<option value="' + esc(s.id) + '"' + (s.id === svc ? ' selected' : '') + '>' + esc(s.name) + '</option>'; } });
            h += '</select></label>';
        }
        h += '</div>';

        if (ctx.canDecide) {
            h += '<div class="cw-gcard__actions">' +
                '<button type="button" class="cw-btn cw-btn--primary gate-confirm">' + esc(t('confirm')) + '</button>' +
                '<button type="button" class="cw-btn gate-correct">' + esc(t('correct')) + '</button>' +
                '<button type="button" class="cw-btn gate-dismiss">' + esc(t('dismiss')) + '</button>';
            if (ui.attach && card.activeOrder) {
                h += '<button type="button" class="cw-btn cw-btn--done gate-attach">' + esc(t('attach')) + '</button>';
            }
            h += '</div>';
        }

        if (ui.error) { h += '<div class="cw-gcard__err" role="alert">' + esc(ui.error) + '</div>'; }

        return h + '</article>';
    }

    return {
        normPlate: normPlate, band: band, pct: pct, newIds: newIds, signature: signature, errorCode: errorCode,
        orderOf: orderOf, confirmBody: confirmBody, defaultService: defaultService, cardHtml: cardHtml,
    };
});
