define(['views/record/detail'], function (Dep) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            // A "Preview" action shows the email with the customer-data placeholders
            // ({name}, {discount}, {offer}, {phone}) filled from a real recipient.
            this.addDropdownItem({
                name: 'previewMailing',
                label: this.translate('preview', 'labels', 'Mailing'),
            });

            // Build the recipient list from a simple filter (has contact + consent + segment),
            // so recipients are not added one by one. Materialises PENDING rows Send delivers to.
            this.addButton({
                name: 'buildAudience',
                label: this.translate('buildAudience', 'labels', 'Mailing'),
            });

            // Primary action: build the audience, record delivery results, send.
            this.addButton({
                name: 'sendMailing',
                label: this.translate('send', 'labels', 'Mailing'),
                style: 'success',
            });
        },

        actionBuildAudience: function () {
            if (!this.model.id) { return; }

            this.createView('dialog', 'custom:views/mailing/modals/build-audience', {
                mailingId: this.model.id,
                segment: this.model.get('segment'),
            }, function (view) {
                view.render();

                this.listenToOnce(view, 'done', function () {
                    // Refresh the recipients panel so the freshly built list is visible.
                    if (this.getView('bottom')) { this.getView('bottom').reRender(); }
                }.bind(this));
            }.bind(this));
        },

        actionSendMailing: function () {
            if (!this.model.id) { return; }

            this.confirm(this.translate('send', 'labels', 'Mailing') + '?', function () {
                Espo.Ui.notify(' ... ');

                Espo.Ajax.postRequest('CarWash/action/sendMailing', {mailingId: this.model.id})
                    .then(function (res) {
                        res = res || {};
                        var msg = this.translate('recipientCount', 'fields', 'Mailing') + ': ' + (res.total || 0) +
                            ' · ' + this.translate('sentCount', 'fields', 'Mailing') + ': ' + (res.sent || 0) +
                            ' · ' + this.translate('failedCount', 'fields', 'Mailing') + ': ' + (res.failed || 0);

                        Espo.Ui.success(msg);
                        this.model.fetch();
                        // Refresh the recipients panel if it is on screen.
                        if (this.getView('bottom')) { this.getView('bottom').reRender(); }
                    }.bind(this))
                    .catch(function () { Espo.Ui.error('Error'); });
            }.bind(this));
        },

        actionPreviewMailing: function () {
            if (!this.model.id) { return; }

            Espo.Ajax.postRequest('CarWash/action/previewMailing', {mailingId: this.model.id})
                .then(function (res) {
                    res = res || {};

                    var esc = function (v) { return $('<div>').text(v == null ? '' : v).html(); };
                    var body = esc(res.body).replace(/\n/g, '<br>');

                    var meta = [];
                    if (res.sampleCustomer) {
                        meta.push(this.translate('previewSample', 'labels', 'Mailing') + ': ' + esc(res.sampleCustomer));
                    }
                    meta.push(this.translate('recipientCount', 'fields', 'Mailing') + ': ' + (res.recipientCount || 0));
                    if (!res.smtpConfigured) {
                        meta.push(this.translate('noSmtp', 'labels', 'Mailing'));
                    }

                    var html =
                        '<div style="margin-bottom:8px;"><b>' + this.translate('subject', 'fields', 'Mailing') + ':</b> ' + esc(res.subject) + '</div>' +
                        '<div style="border:1px solid #eee; border-radius:6px; padding:10px;">' + body + '</div>' +
                        '<div class="text-muted small" style="margin-top:8px;">' + meta.join(' · ') + '</div>';

                    var dialog = Espo.Ui.dialog({
                        header: this.translate('preview', 'labels', 'Mailing'),
                        body: html,
                        backdrop: true,
                        buttons: [
                            {
                                name: 'close',
                                text: this.translate('Close'),
                                onClick: function (d) { d.close(); },
                            },
                        ],
                    });

                    dialog.show();
                }.bind(this));
        },

    });
});
