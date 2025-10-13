<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('partjoo_sync_settings');

// Clear any scheduled hooks
wp_clear_scheduled_hook('partjoo_sync_cron_hook');
