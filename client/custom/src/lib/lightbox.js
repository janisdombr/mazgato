/**
 * A large view of one picture: dark overlay, the image, a caption and a 48 px Close button.
 * Esc and a click on the dark area close it; focus returns to where it was. Used by the
 * "at the entrance" block, the camera frame and the before/after panel.
 * `src` is always a CRM URL (cwMedia / entryPoint=image); it is set as a DOM property, never concatenated into markup.
 */
define(function () {

    function open(src, caption, closeLabel) {
        var prev = document.activeElement;
        var $ = window.jQuery;
        var $o = $('<div class="cw-lightbox" role="dialog" aria-modal="true"></div>');
        var $img = $('<img alt="">');
        var $bar = $('<div class="cw-lightbox__bar"></div>');
        var $close = $('<button type="button" class="cw-btn cw-btn--primary"></button>').text(closeLabel || 'Close');

        $img.attr('src', src);
        $bar.append($close);
        if (caption) { $bar.prepend($('<span class="cw-lightbox__cap"></span>').text(caption)); }
        $o.append($img).append($bar);

        var close = function () {
            $(document).off('keydown.cwlightbox');
            $o.remove();
            if (prev && prev.focus) { prev.focus(); }
        };

        $close.on('click', close);
        $o.on('click', function (e) { if (e.target === $o[0]) { close(); } });
        $(document).on('keydown.cwlightbox', function (e) { if (e.key === 'Escape') { close(); } });
        $('body').append($o);
        $close.trigger('focus');

        return close;
    }

    return {open: open};
});
