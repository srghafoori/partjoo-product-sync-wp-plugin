<?php
if ( ! defined('WP_UNINSTALL_PLUGIN') ) { exit; }

delete_option('partjoo_settings');
delete_option('partjoo_last_sync_status');
delete_option('partjoo_db_version');

global $wpdb;
$table = $wpdb->prefix . 'partjoo_sync_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

$meta_keys = ['_partjoo_sig_current','_partjoo_sig_sent','_partjoo_part_number','_partjoo_part_name','_partjoo_condition','_partjoo_bulk_prices'];
foreach ($meta_keys as $mk) {
    $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $mk) );
}
