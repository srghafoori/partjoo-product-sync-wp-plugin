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

require_once PARTJOO_PLUGIN_DIR . 'includes/interfaces/interface-partjoo-transport.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/interfaces/interface-partjoo-api-client.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-state.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-product-sync.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-config.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-wp-http-transport.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-api-client.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-logger.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-product-repository.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-signature-service.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-payload-builder.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-validation-result.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-product-validator.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-payload-validator.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-sync-orchestrator.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/class-partjoo-container.php';
require_once PARTJOO_PLUGIN_DIR . 'admin/class-partjoo-admin.php';

// Queue foundation (Version 2.0)
require_once PARTJOO_PLUGIN_DIR . 'includes/queue/interface-partjoo-queue-item.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/queue/class-partjoo-queue-item.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/queue/interface-partjoo-queue-repository.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/queue/class-partjoo-queue-repository.php';
require_once PARTJOO_PLUGIN_DIR . 'includes/queue/class-partjoo-queue-service.php';

// Queue processor (Version 2.0 Worker)
require_once PARTJOO_PLUGIN_DIR . 'includes/services/class-partjoo-queue-processor.php';

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

// Cron: sync changed - now enqueues jobs instead of direct sync
add_action('partjoo_cron_sync_changed', function () {
    $sync = PartJoo_Product_Sync::instance();
    $sync->sync_changed_products('cron', false);
    
    // Process the queue after enqueueing (within same cron run).
    $container = PartJoo_Container::instance();
    $processor = $container->get(PartJoo_Container::QUEUE_PROCESSOR);
    if ( $processor ) {
        $batch_size = max( 1, min( 100, (int) ( PartJoo_State::instance()->get_options()['batch_size'] ?? 20 ) ) );
        $result = $processor->process_queue( $batch_size );
        PartJoo_Logger::instance()->log( 'Cron queue processed: ' . $result['processed'] . ' succeeded, ' . $result['failed'] . ' failed.', 'info' );
    }
});
