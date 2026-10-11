/**
 * Administration → Car wash → Import customers: load a CSV exported by another system, confirm which column is
 * which, run a DRY RUN (nothing is written, the report shows what would happen) and only then import.
 * Duplicates (phone / e-mail / company number / plate) are never created twice; marketing consent is never inherited.
 * Same engine as `bin/command import-customers` (Tools/Import/CustomerImporter). Guide: docs/IMPORT.md.
 */
define(['view', 'custom:lib/state'], function (View, State) {

    var esc = State.esc;
    var TEMPLATE = 'name;phone;email;plate;brand;model;reg_number;pvn_number;legal_address;birthday;notes;marketing_email\n' +
        'Jānis Bērziņš;+371 20000000;janis@example.com;AB-1234;Volkswagen;Golf;;;;1985-03-21;prefers morning;no\n' +
        'SIA Example Wash Client;+371 26000000;office@example.lv;CD5678, EF9012;;;40003000000;LV40003000000;Brīvības iela 1, Rīga;;Fleet account;no\n';

    return View.extend({

        templateContent: '<div class="ap-body cw-pad"></div>',

        events: {
            'change .ic-file': function (e) { this.readFile(e.currentTarget.files[0]); },
            'change .ic-encoding': function () { if (this.file) { this.readFile(this.file); } },
            'click .ic-check': function () { this.run(true); },
            'click .ic-import': function () { this.run(false); },
            'click .ic-template': function () { this.download('customers-import-template.csv', '﻿' + TEMPLATE); },
        },

        L: function (key, section) { return this.translate(key, section || 'labels', 'ImportCustomers'); },

        afterRender: function () {
            this.$el.find('.ap-body').html(
                '<section class="cw-section"><h3 class="cw-h2">' + esc(this.L('title')) + '</h3>' +
                '<p class="cw-meta">' + esc(this.L('intro')) + '</p>' +
                '<p><button type="button" class="cw-btn ic-template">' + esc(this.L('template')) + '</button></p>' +
                '<div class="cw-field"><label>' + esc(this.L('file')) + '</label><input type="file" class="ic-file" accept=".csv,.txt,text/csv"></div>' +
                '<div class="cw-field"><label>' + esc(this.L('encoding')) + '</label><select class="form-control ic-encoding">' +
                '<option value="auto">auto</option><option value="utf-8">UTF-8</option><option value="windows-1257">Windows-1257 (Baltic)</option>' +
                '<option value="windows-1251">Windows-1251 (Cyrillic)</option></select></div></section>' +
                '<section class="cw-section ic-map"></section><section class="cw-section ic-report"></section>');
        },

        decode: function (buf) {
            var enc = this.$el.find('.ic-encoding').val();

            if (enc !== 'auto') { return new TextDecoder(enc).decode(buf); }

            try { return new TextDecoder('utf-8', {fatal: true}).decode(buf); } catch (e) { return new TextDecoder('windows-1257').decode(buf); }
        },

        readFile: function (file) {
            if (!file) { return; }

            this.file = file;
            this.$el.find('.ic-report').html('');

            var r = new FileReader();

            r.onload = function () {
                this.text = this.decode(r.result);
                Espo.Ajax.postRequest('ImportData/action/customersHeader', {content: this.text}).then(function (d) {
                    this.header = d;
                    this.paintMap();
                }.bind(this)).catch(function () { this.$el.find('.ic-map').html('<p>' + esc(this.L('readError')) + '</p>'); }.bind(this));
            }.bind(this);
            r.readAsArrayBuffer(file);
        },

        paintMap: function () {
            var d = this.header, self = this;
            var opts = '<option value="">—</option>' + d.header.map(function (h) { return '<option value="' + esc(h) + '">' + esc(h) + '</option>'; }).join('');
            var rows = d.targets.map(function (t) {
                return '<div class="cw-field"><label>' + esc(self.L(t, 'targets')) + '</label><select class="form-control ic-sel" data-target="' + esc(t) + '">' + opts + '</select></div>';
            }).join('');

            this.$el.find('.ic-map').html('<h3 class="cw-h2">' + esc(this.L('mapping')) + ' (' + esc(d.total) + ' ' + esc(this.L('rows')) + ')</h3><div class="cw-fields">' + rows + '</div>' +
                '<div class="cw-actions"><button type="button" class="cw-btn cw-btn--primary ic-check">' + esc(this.L('check')) + '</button> ' +
                '<button type="button" class="cw-btn ic-import" disabled>' + esc(this.L('doImport')) + '</button></div>');

            Object.keys(d.suggest || {}).forEach(function (t) { self.$el.find('.ic-sel[data-target="' + t + '"]').val(d.suggest[t]); });
        },

        mapping: function () {
            var m = {};

            this.$el.find('.ic-sel').each(function () { if ($(this).val()) { m[$(this).data('target')] = $(this).val(); } });

            return m;
        },

        run: function (dry) {
            var $r = this.$el.find('.ic-report');

            if (!dry && !window.confirm(this.L('confirmImport'))) { return; }

            $r.html(State.loading(this.L('loading')));
            Espo.Ajax.postRequest('ImportData/action/customers', {content: this.text, map: this.mapping(), dryRun: dry}).then(function (rep) {
                var h = '<h3 class="cw-h2">' + esc(this.L(rep.dryRun ? 'reportDry' : 'reportDone')) + '</h3><p>' +
                    esc(this.L('customersNew')) + ': <strong>' + esc(rep.customersCreated) + '</strong> · ' + esc(this.L('customersExisting')) + ': ' + esc(rep.customersMatched) +
                    ' · ' + esc(this.L('carsNew')) + ': <strong>' + esc(rep.vehiclesCreated) + '</strong> · ' + esc(this.L('carsExisting')) + ': ' + esc(rep.vehiclesExisting) +
                    ' · ' + esc(this.L('skipped')) + ': ' + esc(rep.skipped) + '</p>';

                if (rep.problems.length) {
                    h += '<table class="table"><thead><tr><th>#</th><th>' + esc(this.L('reason')) + '</th><th></th></tr></thead><tbody>' + rep.problems.slice(0, 50).map(function (p) {
                        return '<tr><td>' + esc(p.line) + '</td><td>' + esc(this.translate(p.reason, 'reasons', 'ImportCustomers')) + '</td><td>' + esc(p.detail) + '</td></tr>';
                    }, this).join('') + '</tbody></table>';
                }

                $r.html(h);
                this.$el.find('.ic-import').prop('disabled', !rep.dryRun || (rep.customersCreated + rep.vehiclesCreated) === 0);
            }.bind(this)).catch(function () { $r.html('<p>' + esc(this.L('readError')) + '</p>'); }.bind(this));
        },

        download: function (name, text) {
            var a = document.createElement('a');

            a.href = URL.createObjectURL(new Blob([text], {type: 'text/csv;charset=utf-8'}));
            a.download = name;
            document.body.appendChild(a);
            a.click();
            setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        },
    });
});
