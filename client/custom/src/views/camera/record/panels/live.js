/**
 * Camera card: "Snapshot" (a frame on request, fetched through the edge node) and "Live view"
 * (the go2rtc / Frigate viewer in an iframe, behind a 5-minute pass) plus "Recordings".
 * Every look is written to the access journal by the server. Hidden actions when the module is off.
 */
define(['views/record/panels/bottom', 'custom:lib/state', 'custom:lib/lightbox'], function (Dep, State, Lightbox) {

    var esc = State.esc;

    return Dep.extend({

        templateContent: '<div class="cw-cam cam-root"></div>',

        events: {
            'click .cam-snap': function () { this.snapshot(); },
            'click .cam-live': function () { this.toggleLive(); },
            'click .cam-clips': function () { this.clips(); },
            'click .cam-frame': function (e) { e.preventDefault(); Lightbox.open($(e.currentTarget).attr('src'), this.model.get('name'), this.t('close', 'Anpr')); },
        },

        t: function (k, scope) { return this.translate(k, 'labels', scope || 'Camera'); },

        moduleOn: function () {
            var list = this.getConfig().get('enabledModules');
            return list == null || list.indexOf('cameras') >= 0;
        },

        onRemove: function () { this.stopLive(); this.stopPolling(); },

        afterRender: function () {
            var $r = this.$el.find('.cam-root');

            if (!this.moduleOn()) {
                $r.html('<div class="cw-meta">' + esc(this.translate('moduleOff', 'labels', 'Anpr')) + '</div>');
                return;
            }

            $r.html(
                '<div class="cw-actions">' +
                '<button type="button" class="cw-btn cw-btn--primary cam-snap"><i class="fas fa-camera" aria-hidden="true"></i> ' + esc(this.t('snapshot')) + '</button>' +
                '<button type="button" class="cw-btn cam-live"><i class="fas fa-play" aria-hidden="true"></i> ' + esc(this.t('liveStart')) + '</button>' +
                '<button type="button" class="cw-btn cam-clips"><i class="fas fa-film" aria-hidden="true"></i> ' + esc(this.t('clips')) + '</button></div>' +
                '<div class="cw-meta">' + esc(this.t('viewAccessNote')) + '</div>' +
                '<div class="cam-out" aria-live="polite"></div><div class="cam-livebox"></div><div class="cam-cliplist"></div>');
        },

        out: function (html) { this.$el.find('.cam-out').html(html); },

        errText: function (xhr) {
            if (xhr && typeof xhr === 'object') { xhr.errorIsHandled = true; }
            var code = '';
            try { code = (xhr.responseJSON || JSON.parse(xhr.responseText)).error; } catch (e) { /* none */ }
            var tr = code ? this.translate(code, 'errors', 'Anpr') : '';
            return tr && tr !== code ? tr : this.translate('error', 'labels', 'Anpr');
        },

        // ---- snapshot on request: queue -> the node fetches it -> poll until Done
        snapshot: function () {
            this.stopPolling();
            this.out('<div class="cw-meta">' + esc(this.t('snapshotWaiting')) + '</div>');
            this.$el.find('.cam-snap').prop('disabled', true);

            Espo.Ajax.postRequest('Cameras/action/snapshot', {cameraId: this.model.id}).then(function (r) {
                this.pollCommand(r.commandId, 0);
            }.bind(this)).catch(function (xhr) {
                this.$el.find('.cam-snap').prop('disabled', false);
                this.out('<div class="cw-neg">' + esc(this.errText(xhr)) + '</div>');
            }.bind(this));
        },

        pollCommand: function (id, n) {
            this.pollTimer = setTimeout(function () {
                Espo.Ajax.postRequest('Cameras/action/commandStatus', {commandId: id}).then(function (r) {
                    if (r.status === 'Done' && r.imageUrl) {
                        this.$el.find('.cam-snap').prop('disabled', false);
                        var $img = $('<img class="cw-cam__frame cam-frame" alt="">').attr('src', r.imageUrl);
                        this.$el.find('.cam-out').empty().append($img);
                    } else if (r.status === 'Failed' || r.status === 'Expired' || n > 30) {
                        this.$el.find('.cam-snap').prop('disabled', false);
                        this.out('<div class="cw-neg">' + esc(this.t('snapshotFailed')) + (r.error ? ': ' + esc(r.error) : '') + '</div>');
                    } else {
                        this.pollCommand(id, n + 1);
                    }
                }.bind(this)).catch(function (xhr) {
                    this.$el.find('.cam-snap').prop('disabled', false);
                    this.out('<div class="cw-neg">' + esc(this.errText(xhr)) + '</div>');
                }.bind(this));
            }.bind(this), n === 0 ? 700 : 1000);
        },

        stopPolling: function () { if (this.pollTimer) { clearTimeout(this.pollTimer); this.pollTimer = null; } },

        // ---- live view (iframe through the reverse proxy; the pass is a cookie scoped to /cam)
        toggleLive: function () { if (this.liveOn) { this.stopLive(); } else { this.startLive(); } },

        startLive: function () {
            Espo.Ajax.postRequest('Cameras/action/streamToken', {cameraId: this.model.id}).then(function (r) {
                var $f = $('<iframe class="cw-cam__live" referrerpolicy="no-referrer" allow="fullscreen" sandbox="allow-scripts allow-same-origin" title="live"></iframe>');
                $f.attr('src', r.url);
                this.$el.find('.cam-livebox').empty().append($f).append('<div class="cw-meta">' + esc(this.t('liveHint')) + '</div>');
                this.liveOn = true;
                this.$el.find('.cam-live').html('<i class="fas fa-stop" aria-hidden="true"></i> ' + esc(this.t('liveStop')));
                this.liveTimer = setTimeout(this.stopLive.bind(this), (r.ttl || 300) * 1000);
            }.bind(this)).catch(function (xhr) {
                this.out('<div class="cw-neg">' + esc(this.errText(xhr)) + '</div>');
            }.bind(this));
        },

        stopLive: function () {
            if (this.liveTimer) { clearTimeout(this.liveTimer); this.liveTimer = null; }
            this.liveOn = false;
            this.$el.find('.cam-livebox').empty();
            this.$el.find('.cam-live').html('<i class="fas fa-play" aria-hidden="true"></i> ' + esc(this.t('liveStart')));
        },

        // ---- recordings listed by the NVR through the node
        clips: function () {
            this.$el.find('.cam-cliplist').html('<div class="cw-meta">' + esc(this.t('snapshotWaiting')) + '</div>');
            Espo.Ajax.postRequest('Cameras/action/clips', {cameraId: this.model.id}).then(function (r) { this.pollClips(r.commandId, 0); }.bind(this))
                .catch(function (xhr) { this.$el.find('.cam-cliplist').html('<div class="cw-neg">' + esc(this.errText(xhr)) + '</div>'); }.bind(this));
        },

        pollClips: function (id, n) {
            setTimeout(function () {
                Espo.Ajax.postRequest('Cameras/action/commandStatus', {commandId: id}).then(function (r) {
                    if (r.status === 'Done') {
                        var dt = this.getDateTime();
                        var rows = (r.clips || []).map(function (c) {
                            var f = function (v) { try { return dt.toDisplay(v.replace('T', ' ').replace('Z', '')); } catch (e) { return v; } };
                            return '<div>' + esc(f(c.start)) + ' – ' + esc(f(c.end)) + (c.label ? ' · ' + esc(c.label) : '') + '</div>';
                        });
                        this.$el.find('.cam-cliplist').html('<div class="cw-cam__clips">' + (rows.join('') || esc(this.t('clipsNone'))) + '</div>');
                    } else if (r.status === 'Failed' || r.status === 'Expired' || n > 30) {
                        this.$el.find('.cam-cliplist').html('<div class="cw-neg">' + esc(this.t('snapshotFailed')) + '</div>');
                    } else { this.pollClips(id, n + 1); }
                }.bind(this));
            }.bind(this), 1000);
        },
    });
});
