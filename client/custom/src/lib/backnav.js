/**
 * Navigation back, for EVERY page (one mechanism, not per entity).
 *
 * A strip above the page: "<- Parent | Home / Parent / This page". It is computed from the address alone:
 *   #                         home (no strip)
 *   #Admin                    Home / Administration
 *   #Admin/<page>             Home / Administration / <page>
 *   #<Scope>                  Home / [scopes.<Scope>.cwBackTo chain] / <Scope list>
 *   #<Scope>/view|edit|create Home / [chain] / <Scope list> / <record>
 * `scopes.<Scope>.cwBackTo` (metadata, e.g. SupplyMovement -> "#Supply", KioskEvent -> "#Admin/kiosk") names the page a
 * screen that has no menu item belongs to. Without it a list goes back to the home page. Pure functions + a painter; the
 * navbar view (custom:views/site/navbar) calls `paint()` on every route, so a page never lacks a way back.
 */
define('custom:lib/backnav', [], function () {

    var MAX_HOPS = 5;

    function parse(hash) {
        var h = String(hash || '').replace(/^#\/?/, '').split('?')[0];
        var parts = h === '' ? [] : h.split('/');

        return {scope: parts[0] || '', rest: parts.slice(1)};
    }

    function isHome(hash) {
        var p = parse(hash);

        return p.scope === '' || p.scope === 'Home';
    }

    /** label of a bare address ("#Supply", "#Admin/license", "#Admin") */
    function labelOf(href, ctx) {
        var p = parse(href);

        if (p.scope === 'Admin') {
            return p.rest.length ? ctx.adminLabel(p.rest[0]) : ctx.t('administration');
        }

        return ctx.plural(p.scope);
    }

    /** the page above a bare address, or '#' for the home page */
    function parentOf(href, ctx) {
        var p = parse(href);

        if (p.scope === 'Admin') {
            return p.rest.length ? '#Admin' : '#';
        }

        return ctx.backTo(p.scope) || '#';
    }

    /**
     * @return {{crumbs: Array<{label: string, href: string|null}>, back: {label: string, href: string}}|null}
     *   null on the home page. `crumbs` run from Home to the current page (href null = current).
     */
    function resolve(hash, ctx) {
        if (isHome(hash)) { return null; }

        var p = parse(hash), chain = [], cur, base;

        if (p.scope === 'Admin') {
            cur = p.rest.length ? ctx.adminLabel(p.rest[0]) : ctx.t('administration');
            chain.push({label: cur, href: null});
            base = p.rest.length ? '#Admin' : null;
        } else if (p.rest.length && ['list', 'kanban', 'calendar'].indexOf(p.rest[0]) === -1) {
            var action = p.rest[0] === 'create' ? ctx.t('create') : p.rest[0] === 'edit' ? ctx.t('edit') : ctx.singular(p.scope);

            chain.push({label: action, href: null});
            chain.push({label: ctx.plural(p.scope), href: '#' + p.scope});
            base = parentOf('#' + p.scope, ctx);
        } else {
            chain.push({label: ctx.plural(p.scope), href: null});
            base = parentOf('#' + p.scope, ctx);
        }

        var hops = 0;

        while (base && base !== '#' && hops++ < MAX_HOPS) {
            chain.push({label: labelOf(base, ctx), href: base});
            base = parentOf(base, ctx);
        }

        chain.push({label: ctx.t('home'), href: '#'});

        var back = chain[1] || {label: ctx.t('home'), href: '#'};

        return {crumbs: chain.slice().reverse(), back: {label: back.label, href: back.href}};
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function html(r) {
        var crumbs = r.crumbs.map(function (c) {
            return c.href == null
                ? '<span class="cw-backbar__here" aria-current="page">' + esc(c.label) + '</span>'
                : '<a class="cw-backbar__crumb" href="' + esc(c.href) + '">' + esc(c.label) + '</a>';
        }).join('<span class="cw-backbar__sep" aria-hidden="true">/</span>');

        return '<a class="cw-backbar__back" href="' + esc(r.back.href) + '"><span class="fas fa-arrow-left" aria-hidden="true"></span> ' +
            esc(r.back.label) + '</a><nav class="cw-backbar__trail" aria-label="breadcrumb">' + crumbs + '</nav>';
    }

    /** the context a view provides: names come from the app's own language + metadata */
    function contextOf(view) {
        var lang = view.getLanguage(), meta = view.getMetadata();
        var tr = function (key, cat, scope) { return lang.translate(key, cat, scope); };

        return {
            t: function (key) {
                var v = tr(key, 'labels', 'CwNav');

                return v === key ? ({home: 'Home', administration: 'Administration', create: 'Create', edit: 'Edit'}[key] || key) : v;
            },
            plural: function (scope) { return tr(scope, 'scopeNamesPlural'); },
            singular: function (scope) { return tr(scope, 'scopeNames'); },
            backTo: function (scope) { return meta.get(['scopes', scope, 'cwBackTo']) || null; },
            adminLabel: function (page) {
                var v = tr(page, 'labels', 'Admin');

                return v === page ? tr(page, 'labels', 'CwNav') : v;
            },
        };
    }

    /** (re)draw the strip for the current address; one element, kept before #main */
    function paint(view) {
        var content = document.getElementById('content'), bar = document.getElementById('cw-backbar');

        if (!content) { return; }

        var r = resolve(window.location.hash, contextOf(view));

        if (!r) {
            if (bar) { bar.parentNode.removeChild(bar); }

            return;
        }

        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'cw-backbar';
            bar.className = 'cw-backbar';
            content.insertBefore(bar, content.firstChild);
        }

        bar.innerHTML = html(r);
    }

    return {parse: parse, isHome: isHome, resolve: resolve, html: html, paint: paint, contextOf: contextOf};
});
