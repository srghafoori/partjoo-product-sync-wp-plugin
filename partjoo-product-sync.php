<?php
/**
 * Plugin Name: PartJoo Product Sync
 * Description: Sync WooCommerce products to PartJoo (API v1.2) with change tracking, stats, and deletion handling.
 * Version: 1.3.0
 * Author: PartJoo
 * License: GPLv2 or later
 * Text Domain: partjoo-product-sync
 * Domain Path: /languages
 */

if ( ! defined('ABSPATH') ) { exit; }

define('PARTJOO_PLUGIN_VERSION', '1.3.0');
define('PARTJOO_PLUGIN_FILE', __FILE__);
define('PARTJOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PARTJOO_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-state.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-product-sync.php';
require_once PARTJOO_PLUGIN_DIR . 'admin/class-partjoo-admin.php';

// Optional WP-CLI
if ( defined('WP_CLI') && WP_CLI ) {
    require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-cli.php';
}

function partjoo_ps_load_textdomain() {
    load_plugin_textdomain('partjoo-product-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'partjoo_ps_load_textdomain', 1);

register_activation_hook(__FILE__, function () {
    PartJoo_State::instance()->install();
    $opts = PartJoo_State::instance()->get_options();
    $recurrence = in_array($opts['cron_recurrence'], ['hourly','twicedaily','daily'], true) ? $opts['cron_recurrence'] : 'hourly';
    if ( ! wp_next_scheduled('partjoo_cron_sync_changed') ) {
        wp_schedule_event( time() + 60, $recurrence, 'partjoo_cron_sync_changed' );
    }
});

register_deactivation_hook(__FILE__, function () {
    $ts = wp_next_scheduled('partjoo_cron_sync_changed');
    if ( $ts ) wp_unschedule_event($ts, 'partjoo_cron_sync_changed');
});

add_action('plugins_loaded', function () {
    if ( class_exists('WooCommerce') ) {
        PartJoo_Product_Sync::instance();
        PartJoo_Admin::instance();
    }
});

// Cron: sync changed
add_action('partjoo_cron_sync_changed', function () {
    PartJoo_Product_Sync::instance()->sync_changed_products();
});
