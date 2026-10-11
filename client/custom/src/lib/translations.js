/**
 * Data localisation: names of services / works / roles are DATA (the client types them in
 * Latvian), translated through the `Translation` table (name -> textLv/textRu/textEn).
 * One cached fetch per page; the map is for the current UI language.
 */
define(function () {

    var FIELD = {lv_LV: 'textLv', ru_RU: 'textRu', en_US: 'textEn'};
    var promise = null;
    var map = null;

    return {

        /** Resolves to {name: translated}. Never rejects — a failure means "no translation". */
        load: function (lang) {
            var field = FIELD[lang];

            if (!field) { map = {}; return Promise.resolve(map); }

            if (!promise) {
                // The API refuses maxSize > 200 (403), so read the table page by page.
                var m = {}, PAGE = 200;
                var page = function (offset) {
                    return Espo.Ajax.getRequest('Translation', {maxSize: PAGE, offset: offset, orderBy: 'name'})
                        .then(function (data) {
                            var list = data.list || [];
                            list.forEach(function (r) { if (r.name && r[field]) { m[r.name] = r[field]; } });
                            return list.length === PAGE ? page(offset + PAGE) : m;
                        });
                };

                promise = page(0)
                    .then(function (res) { map = res; return res; })
                    .catch(function () { map = {}; return map; });
            }

            return promise;
        },

        /** Synchronous lookup once loaded; the original text until then. */
        tr: function (text) { return (map && map[text]) || text; },

        isLoaded: function () { return map !== null; },
    };
});
