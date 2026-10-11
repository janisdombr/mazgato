/**
 * Read-only picture field for the camera pipeline (PlateEvent.snapshot, CameraCommand.file).
 * The camera pictures are not CRM attachments (see Tools/Anpr/Media), so the stock image field cannot
 * show them - and nothing but the `cwMedia` entry point may serve them: it checks the record's real ACL
 * and writes the access journal. The bool field (`hasSnapshot` / `hasFile`) says there is a picture.
 * Field param `mediaType`: plateEvent | command.
 */
define(['views/fields/base', 'custom:lib/lightbox'], function (Dep, Lightbox) {

    return Dep.extend({

        type: 'cwMedia',

        readOnly: true,

        listTemplateContent: '<span class="cw-media cw-media--list"></span>',
        detailTemplateContent: '<span class="cw-media"></span>',
        editTemplateContent: '<span class="cw-media"></span>',

        data: function () { return {}; },

        afterRender: function () {
            var name = this.name;
            var type = this.params.mediaType || this.options.defs && this.options.defs.params && this.options.defs.params.mediaType || 'plateEvent';
            var $slot = this.$el.find('.cw-media');

            if (!this.model.get(name) || !this.model.id) {
                $slot.text('—');
                return;
            }

            var url = '?entryPoint=cwMedia&type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(this.model.id);
            var label = this.translate('enlarge', 'labels', 'Anpr');
            var $img = $('<img class="cw-cam__frame" alt="">').attr('src', url).attr('title', label);

            $img.on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                Lightbox.open(url, this.model.get('name') || '', this.translate('close', 'labels', 'Anpr'));
            }.bind(this));

            $slot.empty().append($img);
        },
    });
});
