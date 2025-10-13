/**
 * Admin scripts for Partjoo Product Sync
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Toggle sync interval field based on auto sync setting
        $('#partjoo_sync_auto_sync').on('change', function() {
            var $intervalField = $('#partjoo_sync_interval').closest('tr');
            
            if ($(this).val() === 'yes') {
                $intervalField.show();
            } else {
                $intervalField.hide();
            }
        }).trigger('change');
        
        // Confirm manual sync
        $('a[href*="action=sync_now"]').on('click', function(e) {
            if (!confirm(partjoo_admin_vars.confirm_sync)) {
                e.preventDefault();
            }
        });
    });
})(jQuery);

