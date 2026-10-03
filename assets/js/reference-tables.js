/* ISPAG Settings → Reference tables : sélecteur de couleur et de média */
jQuery(function ($) {
    $('.ispag-ref-color').wpColorPicker();

    var frame;
    $(document).on('click', '.ispag-ref-pick', function (e) {
        e.preventDefault();
        var $box = $(this).closest('.ispag-ref-media');
        if (!frame) {
            frame = wp.media({ title: 'Choose an image', button: { text: 'Select' }, multiple: false, library: { type: 'image' } });
        }
        frame.off('select').on('select', function () {
            var a = frame.state().get('selection').first().toJSON();
            $box.find('input[type=hidden]').val(a.id);
            var url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
            $box.find('.ispag-ref-media-preview').html('<img src="' + url + '" alt="" style="height:40px;width:auto;">');
        });
        frame.open();
    });
    $(document).on('click', '.ispag-ref-clear', function (e) {
        e.preventDefault();
        var $box = $(this).closest('.ispag-ref-media');
        $box.find('input[type=hidden]').val(0);
        $box.find('.ispag-ref-media-preview').empty();
    });
});
