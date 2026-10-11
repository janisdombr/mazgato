define(['custom:views/dashlets/base'], function (Dep) {

    return Dep.extend({

        name: 'QuickIntake',

        autoFit: true,

        templateContent: `
            <div class="quick-intake cw-pad" data-cw-fit>
                <div class="cw-intake-search">
                    <div class="intake-suggest cw-suggest" style="display:none;"></div>
                    <input type="text" class="form-control field-plate cw-input-plate"
                        placeholder="{{translate 'platePlaceholderHotkey' category='labels' scope='QuickIntake'}}"
                        title="{{translate 'hotkeyHint' category='labels' scope='QuickIntake'}}"
                        aria-keyshortcuts="N" autocomplete="off">
                    <button type="button" class="cw-btn cw-btn--primary action-lookup">
                        {{translate 'lookup' category='labels' scope='QuickIntake'}}
                    </button>
                    <button type="button" class="cw-btn action-noplate"
                        title="{{translate 'noPlateHint' category='labels' scope='QuickIntake'}}">
                        {{translate 'noPlateBtn' category='labels' scope='QuickIntake'}}
                    </button>
                </div>
                <div class="intake-form cw-intake"></div>
            </div>
        `,

        events: {
            'click .action-lookup': function () { this.lookup(); },
            'keydown .field-plate': function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    // Enter takes the highlighted suggestion if the list is open.
                    var $active = this.$el.find('.intake-suggest .suggest-item.active');

                    if ($active.length) { this.pickSuggestion($active.data('plate')); return; }

                    this.hideSuggest();
                    this.lookup();

                    return;
                }

                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); this.moveSuggestion(e.key === 'ArrowDown' ? 1 : -1); return; }
                if (e.key === 'Escape') { this.hideSuggest(); }
            },
            // Type-ahead: plate, then customer name, then driver name.
            'input .field-plate': function () { this.suggestLater(); },
            'blur .field-plate': function () {
                // Delayed so a click on a suggestion still lands.
                setTimeout(function () { this.hideSuggest(); }.bind(this), 200);
            },
            'mousedown .suggest-item': function (e) {
                e.preventDefault();
                this.pickSuggestion($(e.currentTarget).data('plate'));
            },
            'click .action-noplate': function () { this.noPlateStart(); },
            'click .action-intake-close': function () { this.closeIntake(); },
            'click .cw-intake__steps button': function (e) { this.goStep($(e.currentTarget).data('step')); },
            'click .action-step-next': function () { this.goStep((this.step || 1) + 1); },
            'click .action-step-back': function () { this.goStep((this.step || 1) - 1); },
            'click .cw-pay-seg button': function (e) {
                // Chips drive the (hidden) select the logic reads; "change" marks it dirty.
                this.$el.find('.field-payment').val($(e.currentTarget).data('method')).trigger('change');
            },
            'click .cw-perf-seg button': function (e) {
                var $b = $(e.currentTarget);
                var $sel = $b.closest('.cw-role').find('.field-role-employee');
                $sel.val($b.hasClass('is-on') ? '' : $b.data('id')).trigger('change');
            },
            'keydown': function (e) {
                if (e.key === 'Escape' && this.$el.find('.intake-form').hasClass('is-open')) { this.closeIntake(); }
            },
            'click .order-row': function (e) {
                var id = $(e.currentTarget).data('id');
                if (id) { this.getRouter().navigate('#WashOrder/view/' + id, { trigger: true }); }
            },
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            // "N" = new order: focus the plate search from anywhere on the board. Ignored while typing
            // in a field, with a modifier key, or while any dialog / the intake form is open.
            this.hotkeyHandler = function (e) {
                if (e.key !== 'n' && e.key !== 'N') { return; }
                if (e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented) { return; }

                var t = e.target || {};
                var tag = (t.tagName || '').toLowerCase();

                if (tag === 'input' || tag === 'textarea' || tag === 'select' || t.isContentEditable) { return; }
                if ($('.modal.in, .modal.show, .modal-dialog:visible').length) { return; }
                if (this.$el.find('.intake-form').hasClass('is-open')) { return; }

                var el = this.$el.find('.field-plate').get(0);

                if (!el || !$(el).is(':visible')) { return; }

                e.preventDefault();
                el.focus({ preventScroll: false });
                if (el.scrollIntoView) { el.scrollIntoView({ block: 'nearest' }); }
                el.select();
            }.bind(this);

            $(document).on('keydown.cwIntakeHotkey', this.hotkeyHandler);
        },

        afterRender: function () {
            this.loadServices();
            this.loadEmployees();
            this.loadCustomers();
            this.loadRoles();
            this.loadWorkPresets();
            this.loadTranslations();
            this.loadCarModels();
            // preventScroll: autofocus must never make the page jump to the field.
            var plateEl = this.$el.find('.field-plate').get(0);
            if (plateEl) { plateEl.focus({ preventScroll: true }); }
            this.consumeIncomingPlate();
        },

        // Make/model catalogue for the new-vehicle fields — a static JSON shipped with
        // the client code ({"Brand": ["Model", …]}), no external service involved.
        loadCarModels: function () {
            if (this.carModels) { return; }

            $.getJSON('client/custom/lib/car-models.json?r=' + (this.getConfig().get('cacheTimestamp') || 0))
                .done(function (data) {
                    this.carModels = data || {};
                    this.fillBrandDatalist();
                }.bind(this))
                .fail(function () { this.carModels = {}; }.bind(this));
        },

        fillBrandDatalist: function () {
            var $list = this.$el.find('#cw-car-brands');

            if (!$list.length || !this.carModels) { return; }

            $list.html(Object.keys(this.carModels).map(function (b) {
                return '<option value="' + this.esc(b) + '">';
            }.bind(this)).join(''));
        },

        // The model list follows the chosen make; an unknown make offers nothing but
        // both fields stay free text — the catalogue helps, it never restricts.
        fillModelDatalist: function () {
            var $f = this.$el.find('.intake-form');
            var brand = ($f.find('.field-brand').val() || '').trim().toLowerCase();
            var models = [];

            Object.keys(this.carModels || {}).forEach(function (b) {
                if (b.toLowerCase() === brand) { models = this.carModels[b]; }
            }.bind(this));

            this.$el.find('#cw-car-models').html(models.map(function (m) {
                return '<option value="' + this.esc(m) + '">';
            }.bind(this)).join(''));
        },

        // A plate handed over by the "Новый заказ" button on a vehicle/customer page.
        // Consumed once: re-opening the dashboard later must not re-run the lookup.
        consumeIncomingPlate: function () {
            var plate;

            try {
                plate = window.sessionStorage.getItem('cwIntakePlate');
                window.sessionStorage.removeItem('cwIntakePlate');
            } catch (e) { return; }

            if (!plate) { return; }

            this.$el.find('.field-plate').val(plate);

            // The catalogues load asynchronously; wait for them so the prefilled form is
            // complete rather than half-rendered.
            var tries = 0;

            var run = function () {
                if ((this.baseList && this.roleList) || tries++ > 40) {
                    this.lookup();

                    return;
                }

                setTimeout(run, 100);
            }.bind(this);

            run();
        },

        esc: function (v) { return $('<div>').text(v == null ? '' : v).html(); },

        t: function (name) { return this.translate(name, 'labels', 'QuickIntake'); },

        // Data-level localization: role/work/service names are records, not i18n keys.
        // The Translation table maps the stored text to each locale; anything without a
        // row (or with an empty cell) simply shows as entered. See loadTranslations().
        tr: function (text) {
            if (!text) { return text; }
            var map = this.trMap || {};
            return map[text] || text;
        },

        loadTranslations: function () {
            var lang = this.getPreferences().get('language') || this.getConfig().get('language') || 'lv_LV';
            var field = { lv_LV: 'textLv', ru_RU: 'textRu', en_US: 'textEn' }[lang];

            if (!field) { this.trMap = {}; return; }

            Espo.Ajax.getRequest('Translation', { maxSize: 200, orderBy: 'name' })
                .then(function (data) {
                    var map = {};
                    (data.list || []).forEach(function (r) {
                        if (r.name && r[field]) { map[r.name] = r[field]; }
                    });
                    this.trMap = map;
                    this.renderRoles();
                }.bind(this))
                .catch(function () { this.trMap = {}; }.bind(this));
        },

        loadServices: function () {
            // Add-ons appear in the order the business set on WashService.sortOrder;
            // services without one fall to the end, then alphabetically.
            Espo.Ajax.getRequest('WashService', { maxSize: 200, orderBy: 'sortOrder', order: 'asc' })
                .then(function (data) {
                    var list = (data.list || []).filter(function (s) { return s.isActive !== false; });

                    this.baseList = list.filter(function (s) { return s.category !== 'Add-on'; });
                    this.baseList.sort(function (a, b) { return (a.priceRetail || 0) - (b.priceRetail || 0); });

                    this.addonList = list.filter(function (s) { return s.category === 'Add-on'; });
                    this.addonList.sort(function (a, b) {
                        var sa = a.sortOrder == null ? 9999 : a.sortOrder;
                        var sb = b.sortOrder == null ? 9999 : b.sortOrder;

                        return sa !== sb ? sa - sb : (a.name || '').localeCompare(b.name || '');
                    });
                }.bind(this));
        },

        loadEmployees: function () {
            // Only hired staff — people on leave or dismissed must not be assignable.
            Espo.Ajax.getRequest('Employee', {
                maxSize: 200,
                orderBy: 'name',
                where: [{ type: 'equals', attribute: 'status', value: 'Hired' }],
            }).then(function (data) {
                this.employeeList = data.list || [];
            }.bind(this));
        },

        loadCustomers: function () {
            Espo.Ajax.getRequest('Customer', { maxSize: 200, orderBy: 'name' })
                .then(function (data) {
                    this.customerList = data.list || [];
                }.bind(this));
        },

        // The performers offered here are the ACTIVE roles by sort order — roles are
        // editable data, so both the set and the count live in the WorkRole list
        // (Darbi → Lomas): deactivate a role to drop its column, reorder with sortOrder.
        loadRoles: function () {
            Espo.Ajax.getRequest('WorkRole', {
                maxSize: 10,
                orderBy: 'sortOrder',
                order: 'asc',
                where: [{ type: 'isTrue', attribute: 'isActive' }],
            }).then(function (data) {
                this.roleList = data.list || [];
            }.bind(this));
        },

        // GET a whole list in pages (the API caps maxSize at 200; a bigger catalogue used to be cut off and then
        // every service past the 200th line looked like "no works").
        fetchAll: function (entity, params) {
            var all = [];

            var page = function (offset) {
                return Espo.Ajax.getRequest(entity, Object.assign({ maxSize: 200, offset: offset }, params)).then(function (data) {
                    var list = data.list || [];
                    all = all.concat(list);

                    return (list.length === 200 && all.length < (data.total || 0)) ? page(offset + 200) : all;
                });
            };

            return page(0);
        },

        // serviceId -> [{workId, workName, roleId}] — a service is a preset of works.
        loadWorkPresets: function () {
            this.fetchAll('ServiceWork', { orderBy: 'sortOrder', order: 'asc' })
                .then(function (list) {
                    var map = {};

                    list.forEach(function (sw) {
                        if (!sw.serviceId || !sw.workId) { return; }
                        (map[sw.serviceId] = map[sw.serviceId] || []).push({
                            workId: sw.workId,
                            workName: sw.workName,
                            roleOverrideId: sw.roleId || null,
                        });
                    });

                    this.presetMap = map;
                    this.renderServiceWarnings();
                    this.renderWorks();
                }.bind(this));

            this.fetchAll('Work', { orderBy: 'sortOrder', order: 'asc' })
                .then(function (list) {
                    var byId = {};
                    list.forEach(function (w) { byId[w.id] = w; });
                    this.workById = byId;
                    this.renderWorks();
                }.bind(this));
        },

        // A service that brings no works cannot be split between performers: the wage would be 0. Say so on the
        // service itself and point the administrator to the Quick start page where the works are set up.
        renderServiceWarnings: function () {
            var $f = this.$el.find('.intake-form');

            if (!$f.length || !this.presetMap) { return; }

            var text = '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ' + this.esc(this.t('noWorksWarn')) + this.adminLink();

            $f.find('.cw-svc-warn').each(function (i, el) {
                var none = !(this.presetMap[$(el).data('id')] || []).length;
                $(el).prop('hidden', !none).html(none ? text : '');
            }.bind(this));
        },

        // Effective role of a work inside a service: the service's override, else the
        // work's own default role.
        roleOf: function (preset) {
            if (preset.roleOverrideId) { return preset.roleOverrideId; }
            var w = (this.workById || {})[preset.workId];
            return w ? w.defaultRoleId : null;
        },

        loadDrivers: function (customerId) {
            if (!customerId) {
                this.driverList = [];
                this.renderDrivers();
                return;
            }

            Espo.Ajax.getRequest('Driver', {
                maxSize: 50,
                orderBy: 'name',
                where: [{ type: 'equals', attribute: 'customerId', value: customerId }],
            }).then(function (data) {
                this.driverList = data.list || [];
                this.renderDrivers();
            }.bind(this));
        },

        // Picker for attaching a new plate to an already-known company or person.
        customerSelect: function () {
            var opts = ['<option value="">' + this.t('selectCustomer') + '</option>'];

            (this.customerList || []).forEach(function (c) {
                opts.push('<option value="' + c.id + '" data-corp="' + (c.isCorporate ? 1 : 0) + '"' +
                    ' data-contract="' + (c.hasContract ? 1 : 0) + '">' +
                    this.esc(c.name) + (c.isCorporate ? ' — ' + this.t('corporate') : '') + '</option>');
            }.bind(this));

            return '<select class="form-control field-existingCustomerId">' + opts.join('') + '</select>';
        },

        // --- Type-ahead ---------------------------------------------------------------
        // Debounced so typing a plate does not fire a request per keystroke.
        suggestLater: function () {
            clearTimeout(this.suggestTimer);
            this.suggestTimer = setTimeout(function () { this.suggest(); }.bind(this), 220);
        },

        suggest: function () {
            var query = (this.$el.find('.field-plate').val() || '').trim();

            if (query.length < 2) { this.hideSuggest(); return; }

            Espo.Ajax.postRequest('CarWash/action/search', { query: query })
                .then(function (res) {
                    // Ignore a response that arrived after the box was already changed.
                    if ((this.$el.find('.field-plate').val() || '').trim() !== query) { return; }

                    this.renderSuggestions((res || {}).list || []);
                }.bind(this))
                .catch(function (e) { this.logError('quick-intake suggest', e); }.bind(this));
        },

        renderSuggestions: function (list) {
            var $box = this.$el.find('.intake-suggest');

            if (!list.length) { this.hideSuggest(); return; }

            // The icon says which of the three ways the row was matched.
            var icon = { plate: '🚗', customer: '🏢', driver: '👤' };

            $box.html(list.map(function (r, i) {
                return '<div class="suggest-item' + (i === 0 ? ' active' : '') + '" data-plate="' + this.esc(r.plate) + '"' +
'>' +
                    '<span class="cw-suggest__icon">' + (icon[r.type] || '') + '</span>' +
                    '<b>' + this.esc(r.label) + '</b>' +
                    (r.sub ? ' <span class="cw-meta"> — ' + this.esc(r.sub) + '</span>' : '') +
                    '</div>';
            }.bind(this)).join('')).show();

            this.highlightSuggestion();
        },

        highlightSuggestion: function () {
            this.$el.find('.intake-suggest .suggest-item').each(function () {
                var $i = $(this);
                $i.toggleClass('is-active', $i.hasClass('active'));
            });
        },

        moveSuggestion: function (step) {
            var $items = this.$el.find('.intake-suggest:visible .suggest-item');

            if (!$items.length) { return; }

            var idx = $items.index($items.filter('.active'));
            idx = (idx + step + $items.length) % $items.length;

            $items.removeClass('active').eq(idx).addClass('active');
            this.highlightSuggestion();
        },

        pickSuggestion: function (plate) {
            if (!plate) { return; }

            this.$el.find('.field-plate').val(plate);
            this.hideSuggest();
            this.lookup();
        },

        hideSuggest: function () {
            this.$el.find('.intake-suggest').hide().empty();
        },

        // Report a client-side failure to the server log instead of losing it in the
        // console — see CarWash/action/logClientError.
        logError: function (where, e) {
            try {
                console.error('CarWash:', where, e);

                Espo.Ajax.postRequest('CarWash/action/logClientError', {
                    where: where,
                    message: (e && (e.message || e.statusText)) || String(e),
                    stack: (e && e.stack) || '',
                    url: window.location.hash,
                });
            } catch (ignore) { }
        },

        lookup: function () {
            var plate = (this.$el.find('.field-plate').val() || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (!plate) { return; }

            var $btn = this.$el.find('.action-lookup').addClass('disabled');

            this.noPlate = false;

            Espo.Ajax.postRequest('CarWash/action/intake', { plate: plate })
                .then(function (res) {
                    $btn.removeClass('disabled');
                    this.renderPanel(plate, res || {});
                    this.renderHistory(res || {});
                }.bind(this))
                .catch(function () { $btn.removeClass('disabled'); }.bind(this));
        },

        // A car without registration: no lookup is possible, the form opens at once and the SERVER mints the temporary
        // number BN-<yymmdd>-<NNN> (docs/DATA_RULES.md). An order never exists without a car.
        noPlateStart: function () {
            this.noPlate = true;
            this.renderPanel('', { found: false });
            this.renderHistory({});
        },

        // Large plate chip of the dialog head: the real plate, or "temporary number" while there is none.
        plateHeadHtml: function (plate, res) {
            if (!plate) {
                return '<span class="cw-plate cw-plate--missing cw-plate--temp">' + this.esc(this.t('tempPlateHead')) + '</span>';
            }

            return '<span class="cw-plate">' + this.esc(plate) + '</span>' +
                (res && res.plateTemporary ? ' <span class="cw-chip cw-chip--wait">' + this.esc(this.t('tempPlateShort')) + '</span>' : '');
        },

        // The payer is mandatory (the server refuses 400 customer_name_required): for a new customer a name, for an
        // existing one a pick. "Private person" is an explicit button, never a silent default.
        validatePayer: function (res) {
            var $f = this.$el.find('.intake-form');

            if (res && res.found && res.customerId) { return true; }

            if ($f.find('.field-cust-mode:checked').val() === 'existing') {
                if (!$f.find('.field-existingCustomerId').val()) {
                    Espo.Ui.warning(this.t('selectCustomer'));
                    return false;
                }

                return true;
            }

            var $name = $f.find('.field-customerName');

            if (!($name.val() || '').trim()) {
                $name.addClass('is-invalid').trigger('focus');
                Espo.Ui.warning(this.t('payerRequired'));
                return false;
            }

            $name.removeClass('is-invalid');

            return true;
        },

        adminLink: function () {
            return this.getUser().isAdmin()
                ? ' <a href="#Admin/quickStart">' + this.esc(this.t('toQuickStart')) + '</a>'
                : '';
        },

        workerSelect: function (cls, defName, roleId, key, selectedId, visible) {
            var opts = ['<option value="">' + this.t('selectWorker') + '</option>'];

            (this.employeeList || []).forEach(function (e) {
                var sel = selectedId
                    ? (e.id === selectedId ? ' selected' : '')
                    : ((defName && e.name && e.name.indexOf(defName) === 0) ? ' selected' : '');

                opts.push('<option value="' + e.id + '"' + sel + '>' + this.esc(e.name) + '</option>');
            }.bind(this));

            var attrs = (roleId ? ' data-role="' + roleId + '"' : '') + (key ? ' data-key="' + key + '"' : '');
            var style = key && !visible ? ' style="display:none;"' : '';

            return '<select class="form-control ' + cls + '"' + attrs + style + '>' + opts.join('') + '</select>';
        },

        renderPanel: function (plate, res) {
            var t = this.t.bind(this);
            var last = res.lastOrder || {};
            var existingCorporate = !!(res.found && res.customerId && res.isCorporate);
            var known = !!(res.found && res.customerId);
            this.panelRes = res;

            // "Has contract" lives on the Customer. For a known customer it is shown on
            // the card at the right, not asked again here. It is independent of
            // "corporate" — there are contracts with private individuals too.
            var contractLabel =
                '<label class="cw-opt"><input type="checkbox" class="field-contract"> ' + t('contract') + '</label>';

            // ---- step 1 = two panes, both always filled (docs/DATA_RULES.md): the CAR on the left, the PAYER on the right.
            var carPane = '<div class="cw-pane"><div class="cw-h">' + t('car') + '</div>';

            if (res.found) {
                carPane +=
                    '<div class="cw-flex">' + this.plateHeadHtml(res.vehicleName || plate, res) + '</div>' +
                    ((res.vehicleBrand || res.vehicleModel)
                        ? '<div class="cw-meta cw-gap">' + this.esc([res.vehicleBrand, res.vehicleModel].filter(Boolean).join(' ')) + '</div>' : '') +
                    (res.plateTemporary && res.vehicleId
                        ? '<div class="cw-note">' + this.esc(t('tempPlateKnown')) + ' <a href="#Vehicle/view/' + this.esc(res.vehicleId) + '">' + this.esc(t('tempPlateLink')) + '</a></div>' : '') +
                    (known ? '<input type="hidden" class="field-customerId" value="' + this.esc(res.customerId) + '"><div class="intake-drivers cw-gap"></div>' : '');
            } else {
                carPane +=
                    '<div class="cw-flex intake-plate-head">' + this.plateHeadHtml(this.noPlate ? '' : plate, res) + '</div>' +
                    '<label class="cw-opt cw-gap"><input type="checkbox" class="field-noplate"' + (this.noPlate ? ' checked' : '') + '> ' + t('noPlateCheck') + '</label>' +
                    '<div class="cw-meta cw-gap noplate-note"' + (this.noPlate ? '' : ' hidden') + '>' + this.esc(t('noPlateNote')) + '</div>' +
                    // Make/model of the new car. Native datalist autocomplete: the brand
                    // list is the whole catalogue, the model list follows the brand.
                    '<div class="cw-row2 cw-gap">' +
                    '<input type="text" class="form-control field-brand" list="cw-car-brands"' +
                    ' placeholder="' + t('carBrand') + '" autocomplete="off">' +
                    '<input type="text" class="form-control field-model" list="cw-car-models"' +
                    ' placeholder="' + t('carModel') + '" autocomplete="off">' +
                    '</div>' +
                    '<datalist id="cw-car-brands"></datalist>' +
                    '<datalist id="cw-car-models"></datalist>';
            }

            carPane += '</div>';

            var payerPane;

            if (known) {
                payerPane = '<div class="cw-pane intake-history"></div>';
            } else {
                payerPane =
                    '<div class="cw-pane"><div class="cw-h">' + t('payer') + '</div>' +
                    '<div class="cw-meta">' + t(res.found ? 'noCustomerOnCar' : 'newVehicle') + '</div>' +
                    '<div class="cw-row2 cw-gap">' +
                    '<label class="cw-opt"><input type="radio" name="custMode" class="field-cust-mode" value="new" checked> ' + t('newCustomer') + '</label>' +
                    '<label class="cw-opt"><input type="radio" name="custMode" class="field-cust-mode" value="existing"> ' + t('existingCustomer') + '</label>' +
                    '</div>' +
                    '<div class="cust-new cw-gap">' +
                    '<label class="cw-meta" for="qi-payer-name">' + t('payerName') + ' <b class="cw-req">*</b></label>' +
                    '<div class="cw-flex cw-flex--nowrap">' +
                    '<input type="text" id="qi-payer-name" class="form-control field-customerName cw-grow" maxlength="150" autocomplete="off" required placeholder="' + t('customerName') + '">' +
                    '<button type="button" class="cw-btn action-private-person" title="' + this.esc(t('privatePersonHint')) + '">' + this.esc(t('privatePerson')) + '</button>' +
                    '</div>' +
                    '<div class="cw-row2">' +
                    '<input type="text" class="form-control field-phone" placeholder="' + t('phone') + '">' +
                    '<input type="text" class="form-control field-email" placeholder="' + t('email') + '">' +
                    '</div>' +
                    '<div class="cw-row2">' +
                    '<label class="cw-opt"><input type="checkbox" class="field-corporate"> ' + t('corporate') + '</label>' +
                    contractLabel +
                    '</div>' +
                    '<div class="intake-drivers cw-gap"></div>' +
                    '</div>' +
                    '<div class="cust-existing cw-gap" style="display:none;">' + this.customerSelect() + '</div>' +
                    '</div>';
            }

            var customer = '<div class="cw-s1">' + carPane + payerPane + '</div>';

            var base = (this.baseList || []).map(function (s, i) {
                var checked = (last.serviceId ? last.serviceId === s.id : i === 0) ? ' checked' : '';
                return '<div class="cw-svcrow"><label class="cw-opt">' +
                    '<input type="radio" name="baseWash" class="field-base" value="' + s.id + '"' +
                    ' data-retail="' + (s.priceRetail || 0) + '" data-corp="' + (s.priceCorporate || s.priceRetail || 0) + '"' + checked + '> ' +
                    this.esc(this.tr(s.name)) + ' <span class="cw-opt__price">' + this.money(s.priceRetail || 0) + '</span></label>' +
                    '<div class="cw-svc-warn" data-id="' + s.id + '" hidden></div></div>';
            }.bind(this)).join('');

            var addons = (this.addonList || []).map(function (s) {
                var retail = s.priceRetail || 0;
                var corp = s.priceCorporate || s.priceRetail || 0;
                // Each add-on carries its own editable price. The value both feeds the
                // total and is saved on the order (per-line) for the printed receipt.
                return '<div class="cw-svcrow"><div class="cw-addon">' +
                    '<label class="cw-opt">' +
                    '<input type="checkbox" class="field-addon" value="' + s.id + '"' +
                    ' data-retail="' + retail + '" data-corp="' + corp + '"> ' +
                    this.esc(this.tr(s.name)) + '</label>' +
                    // Always editable. Contributes to the total only while its add-on is ticked;
                    // edits are saved on the order (per-line) for the printed receipt.
                    '<input type="number" step="0.01" min="0" class="form-control cw-input-money field-addon-price"' +
                    ' data-id="' + s.id + '" data-retail="' + retail + '" data-corp="' + corp + '"' +
                    ' value="' + retail + '"> <span class="cw-meta">€</span>' +
                    '</div><div class="cw-svc-warn" data-id="' + s.id + '" hidden></div></div>';
            }.bind(this)).join('');

            var steps = ['stepCustomer', 'stepServices', 'stepPerformers'].map(function (k, i) {
                return '<button type="button" data-step="' + (i + 1) + '" class="' + (i === 0 ? 'is-on' : '') + '"><b>' + (i + 1) + '</b> ' + this.esc(t(k)) + '</button>';
            }.bind(this)).join('');

            var html =
                '<div class="cw-intake__dialog" role="dialog" aria-modal="true">' +
                '<div class="cw-intake__head">' +
                    '<span class="intake-plate-top">' + this.plateHeadHtml(plate, res) + '</span>' +
                    '<div class="cw-seg cw-intake__steps">' + steps + '</div>' +
                    '<button type="button" class="cw-btn cw-intake__close action-intake-close" aria-label="' + this.esc(t('close')) + '"><i class="fas fa-xmark"></i></button>' +
                '</div>' +
                '<div class="cw-intake__body">' +
                    '<section class="cw-step" data-step="1">' + customer + '</section>' +
                    '<section class="cw-step" data-step="2" hidden>' +
                        '<div class="cw-step__cols">' +
                        '<div><div class="cw-h">' + t('baseWash') + '</div>' + base + '</div>' +
                        '<div><div class="cw-h">' + t('addons') + '</div>' + addons + '</div>' +
                        '</div>' +
                    '</section>' +
                    '<section class="cw-step" data-step="3" hidden>' +
                        '<div class="intake-noworks cw-pane" hidden></div>' +
                        '<div class="intake-roles cw-roles"></div>' +
                    '</section>' +
                '</div>' +
                // The total, how it is paid and the buttons stay in view whatever the step:
                // the operator decides payment while looking at the number.
                '<div class="cw-intake__foot">' +
                    '<div class="cw-intake__pay">' +
                        '<div class="intake-sub cw-meta" hidden></div>' +
                        '<div class="cw-seg cw-pay-seg">' + this.paymentChips() + '</div>' +
                        '<select class="field-payment" hidden>' + this.paymentOptions() + '</select>' +
                        '<label class="pvn-wrap cw-check" style="display:none;"><input type="checkbox" class="field-pvn" checked> PVN</label>' +
                    '</div>' +
                    '<div class="cw-intake__total"><span>' + t('total') + '</span>' +
                        '<input type="number" step="0.01" min="0" class="form-control intake-total cw-input-total"> <span>EUR</span></div>' +
                    '<div class="intake-discount cw-meta" hidden></div>' +
                    '<div class="cw-actions">' +
                        '<button type="button" class="cw-btn action-step-back" hidden><i class="fas fa-arrow-left"></i>' + this.esc(t('back')) + '</button>' +
                        '<button type="button" class="cw-btn action-step-next"><i class="fas fa-arrow-right"></i>' + this.esc(t('next')) + '</button>' +
                        '<button type="button" class="cw-btn cw-btn--done cw-btn--lg action-create"><i class="fas fa-check"></i>' + this.esc(t('createOrder')) + '</button>' +
                    '</div>' +
                '</div>' +
                '</div>';

            var $form = this.$el.find('.intake-form');

            // The form node survives between lookups: without off() every lookup stacked another
            // copy of each handler below (a work chip then toggled twice = never opened).
            $form.off();
            $form.html(html);
            this.openIntake(plate);
            $form.data('existingCorporate', existingCorporate);
            $form.data('existingContract', !!(res.customer && res.customer.hasContract));

            // "Without registration": the server mints the temporary number; the typed plate is not used then.
            $form.on('change', '.field-noplate', function (e) {
                this.noPlate = $(e.currentTarget).is(':checked');
                $form.find('.noplate-note').prop('hidden', !this.noPlate);
                $form.find('.intake-plate-top, .intake-plate-head').html(this.plateHeadHtml(this.noPlate ? '' : plate, res));
            }.bind(this));
            // "Private person" is the operator's explicit choice of a name, never filled in silently.
            $form.on('click', '.action-private-person', function () {
                $form.find('.field-customerName').val(t('privatePerson')).removeClass('is-invalid');
                $form.find('.field-phone').trigger('focus');
            });
            $form.on('input', '.field-customerName', function (e) { $(e.currentTarget).removeClass('is-invalid'); });
            $form.on('change', '.field-cust-mode', function () {
                var mode = $form.find('.field-cust-mode:checked').val();
                $form.find('.cust-new').toggle(mode === 'new');
                $form.find('.cust-existing').toggle(mode === 'existing');
                this.loadDrivers(mode === 'existing' ? $form.find('.field-existingCustomerId').val() : null);
                this.updateAddonPrices();
                this.recalc();
                this.syncPaymentDefault();
            }.bind(this));
            $form.on('change', '.field-corporate', function () {
                this.updateAddonPrices();
                this.recalc();
                // The driver block is corporate-only; ticking/unticking must show/hide it.
                this.renderDrivers();
                this.customerNamePlaceholder();
                this.syncPaymentDefault();
            }.bind(this));
            // A typed total below the lines is a discount (down to 0 = free): show it, never refuse it.
            $form.on('input change', '.intake-total', function () {
                this.showDiscount();
            }.bind(this));
            $form.on('change', '.field-contract', function () {
                this.syncPaymentDefault();
            }.bind(this));
            $form.on('change', '.field-existingCustomerId', function (e) {
                this.loadDrivers($(e.currentTarget).val() || null);
                this.updateAddonPrices();
                this.recalc();
                this.syncPaymentDefault();
            }.bind(this));
            // An explicit choice sticks — the customer-driven default stops following.
            $form.on('change', '.field-payment', function (e) {
                $(e.currentTarget).addClass('payment-dirty');
                this.updatePvnVisibility();
                this.syncPaymentChips();
            }.bind(this));
            // The model suggestions follow the make as it is typed/picked.
            $form.on('input change', '.field-brand', function () {
                this.fillModelDatalist();
            }.bind(this));
            // "+" reveals two inputs — the shortest way to register a driver on the spot.
            $form.on('click', '.action-new-driver', function () {
                var $n = $form.find('.driver-new');
                $n.toggle();
                $form.find('.field-driverId').prop('disabled', $n.is(':visible'));
                if ($n.is(':visible')) { $n.find('.field-driverName').focus(); }
            });
            $form.on('change', '.field-base', function () {
                this.recalc();
                this.renderWorks();
            }.bind(this));
            $form.on('change', '.field-addon', function () {
                // The price field stays editable; ticking only decides whether it counts.
                this.recalc();
                this.renderWorks();
            }.bind(this));
            // A manually edited add-on price sticks (dirty) and no longer follows the
            // retail/corporate catalogue switch.
            $form.on('input', '.field-addon-price', function (e) {
                $(e.currentTarget).addClass('price-dirty');
                this.recalc();
            }.bind(this));
            // Changing a role's performer re-stamps the works that still follow the role.
            $form.on('change', '.field-role-employee', function () {
                this.syncPerformerChips();
                this.renderWorks();
            }.bind(this));
            // Clicking a work reveals its own performer picker (e.g. give one add-on to
            // someone else). The open state is kept in openWorkKeys, not in the DOM:
            // renderWorks() rebuilds these chips on every service/role change, and a picker
            // that lived only in the DOM silently closed mid-click — the change event then
            // never fired and the performer looked unchangeable.
            $form.on('click', '.work-chip-name', function (e) {
                var key = $(e.currentTarget).closest('.work-chip').data('key');

                if (!key) { return; }

                this.openWorkKeys = this.openWorkKeys || {};

                if (this.openWorkKeys[key]) {
                    delete this.openWorkKeys[key];
                } else {
                    this.openWorkKeys[key] = true;
                }

                this.renderWorks();
            }.bind(this));
            $form.on('change', '.work-employee', function (e) {
                var $s = $(e.currentTarget);
                this.workOverride = this.workOverride || {};
                if ($s.val()) {
                    this.workOverride[$s.data('key')] = $s.val();
                } else {
                    delete this.workOverride[$s.data('key')];
                }
                this.renderWorks();
            }.bind(this));

            this.workOverride = {};
            this.openWorkKeys = {};
            this.lastDriverId = (res.lastOrder || {}).driverId || null;
            this.renderRoles();
            this.loadDrivers(res.found ? res.customerId : null);
            $form.find('.action-create').on('click', function () { this.createOrder(plate, res); }.bind(this));
            $form.on('keydown', function (e) {
                if (e.key !== 'Enter' || $(e.target).is('button, textarea')) { return; }

                e.preventDefault();

                // Enter walks the steps; on the last one it creates the order.
                if ((this.step || 1) < 3) { this.goStep((this.step || 1) + 1); } else { this.createOrder(plate, res); }
            }.bind(this));

            this.updateAddonPrices();
            this.recalc();
            this.fillBrandDatalist();
            this.syncPaymentDefault();
            this.renderServiceWarnings();
        },

        // One column per role: the performer select, then the works that land on them.
        renderRoles: function () {
            var $wrap = this.$el.find('.intake-roles');

            if (!$wrap.length || !(this.roleList || []).length) { return; }

            // Who is pre-selected for each role is a SETTING, not code: WorkRole.defaultEmployee
            // (Darbi → Lomas). Empty = no one pre-selected; the operator picks.
            var html = this.roleList.map(function (role) {
                return '<div class="cw-role" data-role="' + role.id + '" hidden>' +
                    '<div class="cw-h">' + this.esc(this.tr(role.name)) + '</div>' +
                    '<div class="cw-seg cw-perf-seg"></div>' +
                    this.workerSelect('field-role-employee', null, role.id, null, role.defaultEmployeeId) +
                    '<div class="role-works cw-gap" data-role="' + role.id + '"></div>' +
                    '</div>';
            }.bind(this)).join('');

            // Works whose role is empty or switched off would otherwise be invisible and keep no performer.
            html += '<div class="cw-role cw-role--none" data-role="" hidden>' +
                '<div class="cw-h">' + this.esc(this.t('noRole')) + '</div>' +
                '<div class="cw-meta">' + this.esc(this.t('noRoleHint')) + '</div>' +
                '<div class="role-works cw-gap" data-role=""></div></div>';

            $wrap.html(html);
            this.syncPerformerChips();
            this.renderWorks();
        },

        // The works the ticked services bring, grouped under the performer who will do them.
        renderWorks: function () {
            var $f = this.$el.find('.intake-form');

            if (!$f.length || !this.presetMap || !this.workById) { return; }

            var serviceIds = [];
            var $base = $f.find('.field-base:checked');

            if ($base.length) { serviceIds.push($base.val()); }

            $f.find('.field-addon:checked').each(function () { serviceIds.push($(this).val()); });

            var byRole = {};
            // A work is shown at most once: the base wash and an add-on can bring the same
            // work, and without this the panel would repeat it. First service to bring a work
            // wins — mirrors the server-side Hooks/WashOrder/GenerateWorks dedup.
            var seenWork = {};

            serviceIds.forEach(function (serviceId) {
                (this.presetMap[serviceId] || []).forEach(function (preset) {
                    if (seenWork[preset.workId]) { return; }
                    seenWork[preset.workId] = true;

                    var roleId = this.roleOf(preset);

                    if (!(this.roleList || []).some(function (r) { return r.id === roleId; })) { roleId = ''; }

                    (byRole[roleId] = byRole[roleId] || []).push({
                        serviceId: serviceId,
                        workId: preset.workId,
                        workName: preset.workName,
                    });
                }.bind(this));
            }.bind(this));

            $f.find('.role-works').each(function (i, el) {
                var $el = $(el);
                var roleId = $el.data('role');
                var works = byRole[roleId] || [];

                $el.html(works.map(function (w) {
                    var key = w.serviceId + ':' + w.workId;
                    var override = (this.workOverride || {})[key];

                    var isOpen = roleId === '' || !!(this.openWorkKeys || {})[key];

                    return '<div class="work-chip" data-key="' + key + '">' +
                        '<span class="work-chip-name cw-qi-dash cw-clickable">• ' +
                        this.esc(this.tr(w.workName)) +
                        (override ? ' <span class="cw-chip cw-chip--wait">' + this.esc(this.employeeName(override)) + '</span>' : '') +
                        '</span>' +
                        this.workerSelect('work-employee', null, null, key, override, isOpen) +
                        '</div>';
                }.bind(this)).join(''));
            }.bind(this));

            // Only the roles that have works among the SELECTED services get a column; nothing else is offered.
            var any = false;

            $f.find('.cw-role').each(function (i, el) {
                var has = !!(byRole[$(el).data('role')] || []).length;
                any = any || has;
                $(el).prop('hidden', !has);
            });

            var $none = $f.find('.intake-noworks');

            if (serviceIds.length && !any) {
                $none.html('<i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ' + this.esc(this.t('noWorksAtAll')) + this.adminLink()).prop('hidden', false);
            } else {
                $none.prop('hidden', true).empty();
            }
        },

        employeeName: function (id) {
            var found = (this.employeeList || []).filter(function (e) { return e.id === id; })[0];
            return found ? found.name : '';
        },

        // change placeholder text depending on whether the customer is corporate or not
        customerNamePlaceholder: function () {
            var $f = this.$el.find('.intake-form');
            if (!$f.length) { return; }

            var isCorporate = $f.find('.field-corporate').is(':checked');
            var placeholder = isCorporate ? this.t('corporateName') : this.t('customerName');
            $f.find('.field-customerName').attr('placeholder', placeholder);
        },

        renderDrivers: function () {
            var $wrap = this.$el.find('.intake-drivers');

            if (!$wrap.length) { return; }

            // A driver is a corporate concept — a company sends different people to bring
            // its cars. A private individual is their own driver, so hide the whole block.
            if (!this.isCorporate()) { $wrap.empty(); return; }

            var list = this.driverList || [];

            // Preselect whoever brought the car last time, else the customer's primary.
            var current = this.lastDriverId && list.some(function (d) { return d.id === this.lastDriverId; }.bind(this))
                ? this.lastDriverId
                : (list.filter(function (d) { return d.isPrimary; })[0] || {}).id;

            var opts = ['<option value="">' + this.t('noDriver') + '</option>'];

            list.forEach(function (d) {
                opts.push('<option value="' + d.id + '"' + (d.id === current ? ' selected' : '') + '>' +
                    this.esc(d.name) + (d.phone ? ' — ' + this.esc(d.phone) : '') + '</option>');
            }.bind(this));

            $wrap.html(
                '<div class="text-muted">' + this.t('driver') + '</div>' +
                '<div class="cw-flex">' +
                '<select class="form-control field-driverId cw-grow">' + opts.join('') + '</select>' +
                '<button type="button" class="cw-btn action-new-driver" title="' + this.t('newDriver') + '">+</button>' +
                '</div>' +
                '<div class="driver-new cw-gap" style="display:none;">' +
                '<input type="text" class="form-control field-driverName" placeholder="' + this.t('driverName') + '">' +
                '<input type="text" class="form-control field-driverPhone" placeholder="' + this.t('phone') + '">' +
                '</div>'
            );
        },

        // "By subscription" hint (rules are server-side, Tools/Subscriptions): the customer's best subscription that can
        // pay a wash today and how much is left. The box is ticked by default; unticking sends no subscription.
        renderSubscriptionHint: function (res) {
            var t = this.t.bind(this);
            var $s = this.$el.find('.intake-sub');
            var list = (res && res.subscriptions) || [];

            if (!list.length) { $s.attr('hidden', true).html(''); return; }

            var s = list[0];
            var left = s.kind === 'Pack' ? (s.remaining + ' ' + t('subLeft')) : t('subUnlimited');
            var until = s.validTo ? (' · ' + t('subUntil') + ' ' + this.esc(s.validTo)) : '';
            var only = s.serviceName ? (' · ' + t('subOnly') + ' ' + this.esc(this.tr(s.serviceName))) : '';

            $s.html('<label class="cw-check"><input type="checkbox" class="field-use-subscription" checked data-id="' + this.esc(s.id) +
                '" data-service="' + this.esc(s.serviceId || '') + '"> <i class="fas fa-ticket"></i> ' + this.esc(t('bySubscription')) + ': <strong>' +
                this.esc(left) + '</strong>' + until + only + '</label>').removeAttr('hidden');
        },

        renderHistory: function (res) {
            var t = this.t.bind(this);
            var $h = this.$el.find('.intake-history');

            this.renderSubscriptionHint(res);

            if (!res.found || !res.customer) {
                $h.html('');
                return;
            }

            var c = res.customer;
            var st = res.stats || {};

            var card =
                '<div class="cw-qi-panel cw-box">' +
                // The name opens the customer's profile — from the intake panel the
                // operator often needs the full card (contacts, cars, consents).
                '<div class="cw-h"><a href="#Customer/view/' + this.esc(c.id) + '">' +
                this.esc(c.name) + '</a>' +
                (c.isCorporate ? ' <span class="cw-chip cw-chip--progress">' + t('corporate') + '</span>' : '') +
                (c.hasContract
                    ? ' <span class="cw-chip cw-chip--done">' + t('contract') + '</span>'
                    : ' <span class="cw-chip">' + t('noContract') + '</span>') + '</div>' +
                (c.phone ? '<div class="cw-meta">' + this.esc(c.phone) + '</div>' : '') +
                (c.email ? '<div class="cw-meta">' + this.esc(c.email) + '</div>' : '') +
                (c.isCorporate && c.regNumber ? '<div class="cw-meta">' + t('customer') + ': ' + this.esc(c.regNumber) + '</div>' : '') +
                // Notes from the customer card — allergies to brushes, "call before
                // pickup", debts. Line breaks preserved, escaped.
                (c.description
                    ? '<div class="cw-qi-suggest cw-note">' +
                    this.esc(c.description) + '</div>'
                    : '') +
                '<div class="cw-flex cw-flex--tight">' +
                '<span class="cw-chip">' + t('history') + ': ' + (st.totalOrders || 0) + '</span> ' +
                '<span class="cw-chip cw-chip--done">' + t('spent') + ': ' + this.money(st.totalSpent || 0) + '</span>' +
                '</div>' +
                '</div>';

            // History means what actually happened: completed orders only. Anything
            // reserved, in progress or cancelled is not history yet.
            this.historyOrders = (res.orders || []).filter(function (o) {
                return o.status === 'Completed';
            });

            $h.html('<div class="cw-h">' + t('payer') + '</div>' + card + '<div class="cw-h">' + t('history') + '</div><div class="history-table"></div>');

            this.renderHistoryTable();
        },

        renderHistoryTable: function () {
            var t = this.t.bind(this);
            var list = this.historyOrders || [];

            var rows = list.map(function (o) {
                var date = '';

                try { date = o.orderDateTime ? this.getDateTime().toDisplayDate(o.orderDateTime) : ''; } catch (e) { date = (o.orderDateTime || '').slice(0, 10); }

                // All services of the order, localized through the Translation table.
                var services = (o.services && o.services.length ? o.services : [o.serviceName])
                    .filter(function (s) { return s; })
                    .map(function (s) { return this.esc(this.tr(s)); }.bind(this))
                    .join(', ');

                return '<tr class="order-row cw-clickable" data-id="' + o.id + '">' +
                    '<td class="cw-nowrap">' + date + '</td>' +
                    '<td>' + services + '</td>' +
                    '<td class="cw-right cw-nowrap">' + this.money(o.finalPrice) + '</td>' +
                    '</tr>';
            }.bind(this)).join('');

            this.$el.find('.history-table').html(
                rows
                    ? '<div class="cw-scroll"><table class="cw-table"><tbody>' + rows + '</tbody></table></div>'
                    : '<div class="cw-meta">' + t('noHistory') + '</div>'
            );
        },

        isCorporate: function () {
            var $f = this.$el.find('.intake-form');
            if ($f.data('existingCorporate')) { return true; }

            if ($f.find('.field-cust-mode:checked').val() === 'existing') {
                return $f.find('.field-existingCustomerId option:selected').data('corp') == 1;
            }

            return $f.find('.field-corporate').is(':checked');
        },

        hasContract: function () {
            var $f = this.$el.find('.intake-form');

            if ($f.data('existingContract')) { return true; }

            if ($f.find('.field-cust-mode:checked').val() === 'existing') {
                return $f.find('.field-existingCustomerId option:selected').data('contract') == 1;
            }

            return $f.find('.field-contract').is(':checked');
        },

        paymentOptions: function () {
            return ['Cash', 'Card', 'Invoice'].map(function (v) {
                return '<option value="' + v + '">' +
                    this.esc(this.getLanguage().translateOption(v, 'paymentMethod', 'WashOrder')) +
                    '</option>';
            }.bind(this)).join('');
        },

        // A corporate or contract customer is billed, not charged at the counter — the
        // method follows the customer UNTIL the operator picks one by hand (dirty).
        syncPaymentDefault: function () {
            var $f = this.$el.find('.intake-form');
            var $sel = $f.find('.field-payment');

            if (!$sel.length) { return; }

            if (!$sel.hasClass('payment-dirty')) {
                $sel.val(this.isCorporate() || this.hasContract() ? 'Invoice' : 'Cash');
            }

            this.updatePvnVisibility();
            this.syncPaymentChips();
        },

        // ---- the intake dialog (3 steps, sticky total) ----

        openIntake: function (plate) {
            this.step = 1;
            this.$el.find('.intake-form').addClass('is-open');
            $('body').addClass('cw-modal-open');
            this.goStep(1);
        },

        closeIntake: function () {
            this.panelRes = null;
            this.$el.find('.intake-form').removeClass('is-open').off().empty();
            $('body').removeClass('cw-modal-open');
            this.$el.find('.field-plate').focus();
        },

        onRemove: function () {
            $(document).off('keydown.cwIntakeHotkey', this.hotkeyHandler);
            $('body').removeClass('cw-modal-open');
        },

        goStep: function (n) {
            n = Math.max(1, Math.min(3, parseInt(n, 10) || 1));

            // No leaving step 1 without a payer (the server insists as well).
            if (n > 1 && this.panelRes && !this.validatePayer(this.panelRes)) { n = 1; }
            this.step = n;

            var $f = this.$el.find('.intake-form');

            $f.find('.cw-step').each(function () { $(this).prop('hidden', $(this).data('step') !== n); });
            $f.find('.cw-intake__steps button').each(function () { $(this).toggleClass('is-on', $(this).data('step') === n); });
            $f.find('.action-step-back').prop('hidden', n === 1);
            $f.find('.action-step-next').prop('hidden', n === 3);
            $f.find('.cw-intake__body').scrollTop(0);
        },

        // Payment is three chips (D9) driving the hidden select that the logic already reads.
        paymentChips: function () {
            return ['Cash', 'Card', 'Invoice'].map(function (v) {
                return '<button type="button" data-method="' + v + '">' +
                    this.esc(this.getLanguage().translateOption(v, 'paymentMethod', 'WashOrder')) + '</button>';
            }.bind(this)).join('');
        },

        syncPaymentChips: function () {
            var v = this.$el.find('.field-payment').val();

            this.$el.find('.cw-pay-seg button').each(function () {
                var on = $(this).data('method') === v;
                $(this).toggleClass('is-on', on).attr('aria-pressed', on);
            });
        },

        // Performers are chips while the crew is small (<= 6), the stock select beyond that.
        syncPerformerChips: function () {
            var list = this.employeeList || [];
            var chips = list.length > 0 && list.length <= 6;

            this.$el.find('.cw-role').each(function (i, el) {
                var $role = $(el);
                var $sel = $role.find('.field-role-employee');
                var cur = $sel.val();

                $sel.toggle(!chips);
                $role.find('.cw-perf-seg').html(chips ? list.map(function (e) {
                    return '<button type="button" data-id="' + e.id + '" class="' + (e.id === cur ? 'is-on' : '') + '" aria-pressed="' + (e.id === cur) + '">' + $('<div>').text(e.name).html() + '</button>';
                }).join('') : '');
            });
        },

        // The PVN checkbox belongs to the Invoice method only. Hiding it re-checks it,
        // so a stale untick never leaks into a cash/card order.
        updatePvnVisibility: function () {
            var $f = this.$el.find('.intake-form');
            var invoice = $f.find('.field-payment').val() === 'Invoice';

            $f.find('.pvn-wrap').toggle(invoice);

            if (!invoice) { $f.find('.field-pvn').prop('checked', true); }
        },

        recalc: function () {
            var $f = this.$el.find('.intake-form');
            var attr = this.isCorporate() ? 'corp' : 'retail';
            var total = 0;

            var $base = $f.find('.field-base:checked');
            if ($base.length) { total += parseFloat($base.data(attr)) || 0; }

            // Add-ons contribute their own (editable) price field, not the catalogue value.
            $f.find('.field-addon:checked').each(function () {
                var $p = $f.find('.field-addon-price[data-id="' + $(this).val() + '"]');
                total += parseFloat($p.val()) || 0;
            });

            $f.find('.intake-total').val(Math.round(total * 100) / 100);
            this.showDiscount();
        },

        // Sum of the lines as the form stands (base + ticked add-ons at their own prices).
        linesSum: function () {
            var $f = this.$el.find('.intake-form');
            var attr = this.isCorporate() ? 'corp' : 'retail';
            var sum = 0;
            var $base = $f.find('.field-base:checked');

            if ($base.length) { sum += parseFloat($base.data(attr)) || 0; }

            $f.find('.field-addon:checked').each(function () {
                sum += parseFloat($f.find('.field-addon-price[data-id="' + $(this).val() + '"]').val()) || 0;
            });

            return Math.round(sum * 100) / 100;
        },

        showDiscount: function () {
            var $f = this.$el.find('.intake-form');
            var $hint = $f.find('.intake-discount');
            var sum = this.linesSum();
            var raw = $f.find('.intake-total').val();
            var typed = parseFloat(raw);

            if (raw === '' || isNaN(typed) || typed < 0 || sum <= 0 || typed >= sum - 0.005) {
                $hint.prop('hidden', true).text('');
                return;
            }

            var off = Math.round((sum - typed) * 100) / 100;
            var pct = Math.round((off / sum) * 1000) / 10;

            $hint.text(this.t('discountHint')
                .replace('{amount}', this.money(off))
                .replace('{percent}', String(pct).replace('.', ','))
                .replace('{sum}', this.money(sum))).prop('hidden', false);
        },

        // Keep add-on price fields that the operator has NOT edited in step with the
        // retail/corporate switch; a manually edited (dirty) field is left alone.
        updateAddonPrices: function () {
            var $f = this.$el.find('.intake-form');
            var attr = this.isCorporate() ? 'corp' : 'retail';

            $f.find('.field-addon-price').each(function () {
                var $p = $(this);
                if ($p.hasClass('price-dirty')) { return; }
                $p.val(parseFloat($p.data(attr)) || 0);
            });
        },

        createOrder: function (plate, res) {
            var $f = this.$el.find('.intake-form');

            var addonIds = [];
            var addonPrices = {};
            $f.find('.field-addon:checked').each(function () {
                var id = $(this).val();
                addonIds.push(id);
                var $p = $f.find('.field-addon-price[data-id="' + id + '"]');
                addonPrices[id] = parseFloat($p.val()) || 0;
            });

            // A negative price or total is refused here (the server refuses it too: 400 naming the field).
            // A total BELOW the lines is a discount, any size down to 0, and is posted as typed.
            var typedTotal = parseFloat($f.find('.intake-total').val() || '0') || 0;
            var negative = typedTotal < 0;

            Object.keys(addonPrices).forEach(function (id) {
                if (addonPrices[id] < 0) { negative = true; }
            });

            if (negative) {
                Espo.Ui.warning(this.t('negativeAmount'));
                return;
            }

            // Who performs each role on this order…
            var roleAssignments = [];

            $f.find('.cw-role:not([hidden]) .field-role-employee').each(function () {
                var employeeId = $(this).val();
                var roleId = $(this).data('role');

                if (roleId && employeeId) {
                    roleAssignments.push({ roleId: roleId, employeeId: employeeId });
                }
            });

            // …and the individual works handed to somebody else.
            var workAssignments = [];

            Object.keys(this.workOverride || {}).forEach(function (key) {
                var parts = key.split(':');
                workAssignments.push({
                    serviceId: parts[0],
                    workId: parts[1],
                    employeeId: this.workOverride[key],
                });
            }.bind(this));

            if (!this.validatePayer(res)) { this.goStep(1); return; }

            var payload = {
                // No registration: an empty plate, the server mints the temporary number.
                plate: (this.noPlate && !res.found) ? '' : plate,
                finalPrice: ($f.find('.intake-total').val() === '' || isNaN(parseFloat($f.find('.intake-total').val()))) ? null : parseFloat($f.find('.intake-total').val()),
                serviceId: $f.find('.field-base:checked').val() || null,
                addonIds: addonIds,
                addonPrices: addonPrices,
                driverId: $f.find('.field-driverId').val() || null,
                driverName: ($f.find('.field-driverName').val() || '').trim() || null,
                driverPhone: ($f.find('.field-driverPhone').val() || '').trim() || null,
                roleAssignments: roleAssignments,
                workAssignments: workAssignments,
                hasContract: $f.find('.field-contract').is(':checked'),
                paymentMethod: $f.find('.field-payment').val() || null,
                pvnEnabled: $f.find('.field-pvn').is(':checked'),
                brand: ($f.find('.field-brand').val() || '').trim() || null,
                model: ($f.find('.field-model').val() || '').trim() || null,
            };

            var $sub = this.$el.find('.field-use-subscription:checked');

            if ($sub.length && res.found && res.customerId && (!$sub.data('service') || $sub.data('service') === payload.serviceId)) {
                payload.subscriptionId = String($sub.data('id'));
            }

            if (res.found && res.customerId) {
                payload.customerId = res.customerId;
            } else if ($f.find('.field-cust-mode:checked').val() === 'existing') {
                payload.customerId = $f.find('.field-existingCustomerId').val() || null;

                if (!payload.customerId) {
                    Espo.Ui.warning(this.t('selectCustomer'));
                    return;
                }
            } else {
                payload.customerName = ($f.find('.field-customerName').val() || '').trim();
                payload.phone = ($f.find('.field-phone').val() || '').trim();
                payload.email = ($f.find('.field-email').val() || '').trim();
                payload.isCorporate = $f.find('.field-corporate').is(':checked');
            }

            // D24: visible feedback — spinner + "Creating…" and the form locked, not a silent disable.
            var $btn = $f.find('.action-create');
            var idle = $btn.html();

            if ($btn.hasClass('is-busy')) { return; }

            $btn.addClass('is-busy').html('<i class="fas fa-spinner fa-spin"></i>' + this.esc(this.t('creating')));
            $f.find('input, select, button').not($btn).prop('disabled', true);

            Espo.Ajax.postRequest('CarWash/action/createOrder', payload)
                .then(function (r) {
                    Espo.Ui.success(this.t('orderCreated') + (r.plateTemporary ? ' · ' + this.t('tempPlateAssigned').replace('{plate}', r.vehiclePlate || '') : ''));
                    this.closeIntake();
                    this.getRouter().navigate('#WashOrder/view/' + r.orderId, { trigger: true });
                }.bind(this))
                .catch(function () {
                    // e.g. 409 one-car-one-ramp: the server message is shown by Espo; the form stays as it was.
                    $btn.removeClass('is-busy').html(idle);
                    $f.find('input, select, button').prop('disabled', false);
                }.bind(this));
        },

    });
});
