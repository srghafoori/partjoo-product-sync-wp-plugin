<?php
/**
 * Plugin Name: همگام‌سازی محصولات با پارتجو
 * Plugin URI: https://partjoo.com
 * Description: افزونه ارسال خودکار محصولات ووکامرس به موتور جستجوی پارتجو
 * Version: 1.0.0
 * Author: پارتجو
 * Author URI: https://partjoo.com
 * Text Domain: partjoo-sync
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PARTJOO_SYNC_VERSION', '1.0.0');
define('PARTJOO_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PARTJOO_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PARTJOO_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include required files
require_once PARTJOO_SYNC_PLUGIN_DIR . 'includes/class-partjoo-product-sync.php';

// Check if WooCommerce is active
function partjoo_sync_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'partjoo_sync_woocommerce_notice');
        return false;
    }
    return true;
}

// Display WooCommerce missing notice
function partjoo_sync_woocommerce_notice() {
    ?>
    <div class="error">
        <p><?php _e('افزونه همگام‌سازی محصولات با پارتجو نیاز به نصب و فعال‌سازی افزونه ووکامرس دارد.', 'partjoo-sync'); ?></p>
    </div>
    <?php
}

// Initialize the plugin
function partjoo_sync_init() {
    // Load text domain for translations
    load_plugin_textdomain('partjoo-sync', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    
    // Check if WooCommerce is active
    if (partjoo_sync_check_woocommerce()) {
        // Initialize the main plugin class
        Partjoo_Product_Sync::get_instance();
    }
}
add_action('plugins_loaded', 'partjoo_sync_init');

// Plugin activation
register_activation_hook(__FILE__, 'partjoo_sync_activate');
function partjoo_sync_activate() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('افزونه همگام‌سازی محصولات با پارتجو نیاز به نصب و فعال‌سازی افزونه ووکامرس دارد.', 'partjoo-sync'));
    }
    
    // Create default settings
    $default_settings = array(
        'domain' => '',
        'auto_sync' => 'yes',
        'sync_interval' => 'daily'
    );
    
    add_option('partjoo_sync_settings', $default_settings);
    
    // Schedule sync
    if (!wp_next_scheduled('partjoo_sync_cron_hook')) {
        wp_schedule_event(time(), 'daily', 'partjoo_sync_cron_hook');
    }
}

// Plugin deactivation
register_deactivation_hook(__FILE__, 'partjoo_sync_deactivate');
function partjoo_sync_deactivate() {
    // Clear scheduled hooks
    wp_clear_scheduled_hook('partjoo_sync_cron_hook');
}
