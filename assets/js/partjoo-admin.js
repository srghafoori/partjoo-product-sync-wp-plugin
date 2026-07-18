(function ($) {
    $(document).on('click', '.partjoo-sync-now', function () {
        var $btn = $(this);
        var pid = $btn.data('id');
        var force = $btn.data('force') ? 1 : 0;
        $btn.prop('disabled', true).text(force ? 'Forcing...' : 'Syncing...');
        $.post(PartJooAjax.ajaxurl, {
            action: 'partjoo_sync_single',
            product_id: pid,
            force: force,
            nonce: PartJooAjax.nonce
        }).done(function (r) {
            alert((r && r.data && r.data.message) ? r.data.message : 'Done');
        }).fail(function () {
            alert('Failed');
        }).always(function () {
            $btn.prop('disabled', false).text(force ? 'Force resend' : 'Sync this item');
        });
    });
})(jQuery);
