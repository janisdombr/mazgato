/**
 * "Build recipients" dialog for a mailing. Loads the audience from a simple filter
 * (segment + "only consented"), shows exactly who qualifies (name + contact) as a checkbox
 * list, and writes the kept customers as PENDING MailingRecipient rows — so the operator never
 * adds recipients one by one. Backed by CarWash/action/buildAudience.
 */
define(['views/modal'], function (Modal) {

    return Modal.extend({

        templateContent:
            '<div class="mailing-build-audience">' +
                '<div class="row" style="margin-bottom:10px;">' +
                    '<div class="col-sm-6">' +
                        '<label class="control-label small ba-lbl-segment"></label>' +
                        '<select class="form-control input-sm ba-segment"></select>' +
                    '</div>' +
                    '<div class="col-sm-6" style="padding-top:24px;">' +
                        '<label style="font-weight:normal;"><input type="checkbox" class="ba-consent" checked> <span class="ba-lbl-consent"></span></label>' +
                    '</div>' +
                '</div>' +
                '<div class="ba-summary text-muted small" style="margin-bottom:6px;"></div>' +
                '<div class="ba-list" style="max-height:340px; overflow:auto; border:1px solid #eee; border-radius:6px; padding:8px;"></div>' +
            '</div>',

        setup: function () {
            this.mailingId = this.options.mailingId;
            this.segment = this.options.segment || 'All';

            this.headerText = this.translate('buildAudience', 'labels', 'Mailing');

            this.buttonList = [
                {name: 'add', text: this.translate('addRecipients', 'labels', 'Mailing'), style: 'primary'},
                {name: 'cancel', text: this.translate('Cancel')},
            ];
        },

        afterRender: function () {
            var lang = this.getLanguage();

            this.$el.find('.ba-lbl-segment').text(this.translate('segment', 'fields', 'Mailing'));
            this.$el.find('.ba-lbl-consent').text(this.translate('onlyConsented', 'labels', 'Mailing'));

            var $seg = this.$el.find('.ba-segment');
            ['All', 'Corporate', 'Individual'].forEach(function (v) {
                $seg.append($('<option>').val(v).text(lang.translateOption(v, 'segment', 'Mailing')));
            });
            $seg.val(this.segment);

            this.$el.find('.ba-segment, .ba-consent').on('change', this.loadList.bind(this));
            this.loadList();
        },

        loadList: function () {
            var $list = this.$el.find('.ba-list');
            var $sum = this.$el.find('.ba-summary');

            $list.html('<div class="text-muted small">' + this.translate('pleaseWait', 'messages') + '</div>');

            Espo.Ajax.postRequest('CarWash/action/buildAudience', {
                mailingId: this.mailingId,
                segment: this.$el.find('.ba-segment').val(),
                onlyConsented: this.$el.find('.ba-consent').is(':checked'),
            }).then(function (res) {
                res = res || {};
                var list = res.list || [];
                var esc = function (v) { return $('<div>').text(v == null ? '' : v).html(); };

                if (!list.length) {
                    $list.html('<div class="text-muted">' + this.translate('noRecipients', 'labels', 'Mailing') + '</div>');
                    $sum.text('');
                    this.updateCount();

                    return;
                }

                var rows = list.map(function (c) {
                    return '<label style="display:block; font-weight:normal; margin:2px 0;">' +
                        '<input type="checkbox" class="ba-row" value="' + esc(c.id) + '" checked> ' +
                        esc(c.name) + ' <span class="text-muted small">' + esc(c.contact) + '</span></label>';
                }).join('');

                $list.html(rows);
                $list.find('.ba-row').on('change', this.updateCount.bind(this));
                $sum.text(this.translate('found', 'labels', 'Mailing') + ': ' + list.length);
                this.updateCount();
            }.bind(this)).catch(function () {
                $list.html('<div class="text-danger">' + this.translate('error', 'messages') + '</div>');
            }.bind(this));
        },

        checkedIds: function () {
            return this.$el.find('.ba-row:checked').map(function () { return $(this).val(); }).get();
        },

        updateCount: function () {
            var n = this.checkedIds().length;
            var base = this.translate('addRecipients', 'labels', 'Mailing');

            this.$el.closest('.modal').find('button[data-name="add"]').text(n ? base + ' (' + n + ')' : base);
        },

        actionAdd: function () {
            var ids = this.checkedIds();

            if (!ids.length) {
                Espo.Ui.warning(this.translate('noRecipients', 'labels', 'Mailing'));

                return;
            }

            Espo.Ui.notify(' ... ');

            Espo.Ajax.postRequest('CarWash/action/buildAudience', {
                mailingId: this.mailingId,
                materialize: true,
                customerIds: ids,
            }).then(function (res) {
                Espo.Ui.success(this.translate('recipientsAdded', 'labels', 'Mailing') + ': ' + ((res || {}).count || 0));
                this.trigger('done');
                this.close();
            }.bind(this));
        },
    });
});
