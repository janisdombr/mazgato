/*
 * AGPL section 13: a "Source code" link wherever the interface shows the legal notices - the footer
 * line (every screen, the login screen included) and the About page. Loaded at boot from
 * app/client.json (scriptList). It only ADDS a link: the stock "© EspoCRM, Inc." notice is never
 * touched, hidden or reworded (docs/AGPL.md). The address comes from the public entry point
 * ?entryPoint=sourceCode (Settings -> sourceCodeUrl); with none configured nothing is added.
 */
(function () {
    'use strict';

    var LABEL = {lv: 'Pirmkods', ru: 'Исходный код', en: 'Source code'};
    var url = null, asked = false, defLang = '';

    function lang() {
        var l = (document.documentElement.lang || '').toLowerCase().slice(0, 2);

        if (!LABEL[l]) {   // the app caches the user's language file as "...-app-language-<ru_RU>"
            try {
                for (var i = 0; i < localStorage.length && !LABEL[l]; i++) {
                    var m = /-app-language-([a-z]{2})_[A-Z]{2}$/.exec(localStorage.key(i) || '');

                    if (m) { l = m[1]; }
                }
            } catch (e) { l = ''; }
        }
        if (!LABEL[l]) {
            try { l = (localStorage.getItem('language') || '').toLowerCase().slice(0, 2); } catch (e) { l = ''; }
        }
        if (!LABEL[l]) { l = defLang; }
        if (!LABEL[l]) { l = (navigator.language || 'en').toLowerCase().slice(0, 2); }

        return LABEL[l] ? l : 'en';
    }

    function link() {
        var a = document.createElement('a');

        a.className = 'cw-source-link';
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = LABEL[lang()];

        return a;
    }

    function paint() {
        if (!url) { return; }

        var credit = document.querySelector('footer p.credit');

        if (credit && !credit.querySelector('.cw-source-link')) {
            credit.appendChild(document.createTextNode(' · '));
            credit.appendChild(link());
        }

        if (/#About\b/.test(location.hash)) {
            var box = document.querySelector('.page-header + .row .panel-body .complex-text');

            if (box && !box.parentNode.querySelector('.cw-source-link')) {
                var p = document.createElement('p');

                p.appendChild(link());
                box.parentNode.appendChild(p);
            }
        }
    }

    function start() {
        if (!asked) {
            asked = true;
            fetch('?entryPoint=sourceCode&format=json', {credentials: 'same-origin', cache: 'no-store'})
                .then(function (r) { return r.ok ? r.json() : {}; })
                .then(function (d) {
                    defLang = (d && d.lang) || '';
                    url = d && /^https?:\/\//i.test(d.url || '') ? d.url : null;
                    paint();
                })
                .catch(function () {});
        }

        new MutationObserver(paint).observe(document.body, {childList: true, subtree: true});
    }

    if (document.body) { start(); } else { document.addEventListener('DOMContentLoaded', start); }
})();
