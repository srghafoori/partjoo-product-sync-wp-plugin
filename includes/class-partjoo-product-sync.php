<?php
/**
 * Main plugin class
 *
 * @package Partjoo_Product_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class
 */
class Partjoo_Product_Sync {
    
    // Class instance
    private static $instance = null;
    
    // Plugin settings
    private $settings;
    
    /**
     * Get class instance
     *
     * @return Partjoo_Product_Sync
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Class constructor
     */
    private function __construct() {
        // Load settings
        $this->settings = get_option('partjoo_sync_settings', array(
            'domain' => '',
            'auto_sync' => 'yes',
            'sync_interval' => 'daily'
        ));
        
        // Load admin functionality
        if (is_admin()) {
            require_once PARTJOO_SYNC_PLUGIN_DIR . 'admin/class-partjoo-admin.php';
            new Partjoo_Admin($this->settings);
        }
        
        // Register cron hook for automatic sync
        add_action('partjoo_sync_cron_hook', array($this, 'sync_all_products'));
        
        // Product hooks
        add_action('woocommerce_update_product', array($this, 'sync_single_product'));
        add_action('woocommerce_new_product', array($this, 'sync_single_product'));
        
        // Activate cron job if auto sync is enabled
        if ($this->settings['auto_sync'] === 'yes') {
            $this->schedule_sync();
        }
    }
    
    /**
     * Schedule sync
     */
    public function schedule_sync() {
        // Clear previous schedule
        wp_clear_scheduled_hook('partjoo_sync_cron_hook');
        
        // Create new schedule
        if (!wp_next_scheduled('partjoo_sync_cron_hook')) {
            wp_schedule_event(time(), $this->settings['sync_interval'], 'partjoo_sync_cron_hook');
        }
    }
    
    /**
     * Sync single product
     *
     * @param int $product_id Product ID
     * @return bool Success status
     */
    public function sync_single_product($product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return false;
        }
        
        // Create product data array
        $product_data = $this->prepare_product_data($product);
        
        // Send to Partjoo API
        return $this->send_to_partjoo(array($product_data));
    }
    
    /**
     * Sync all products
     *
     * @return bool Success status
     */
    public function sync_all_products() {
        // Check if domain exists
        if (empty($this->settings['domain'])) {
            return false;
        }
        
        $args = array(
            'status' => 'publish',
            'limit' => -1,
            'return' => 'ids',
        );
        
        $product_ids = wc_get_products($args);
        
        if (empty($product_ids)) {
            return true; // No products exist
        }
        
        // Split products into smaller chunks for sending
        $chunks = array_chunk($product_ids, 50);
        $success = true;
        
        foreach ($chunks as $chunk) {
            $products_data = array();
            
            foreach ($chunk as $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $products_data[] = $this->prepare_product_data($product);
                }
            }
            
            if (!empty($products_data)) {
                $result = $this->send_to_partjoo($products_data);
                if (!$result) {
                    $success = false;
                }
            }
        }
        
        return $success;
    }
    
    /**
     * Prepare product data
     *
     * @param WC_Product $product WooCommerce product
     * @return array Product data
     */
    private function prepare_product_data($product) {
        $product_data = array(
            'title' => $product->get_name(),
            'price' => (float) $product->get_price(),
            'stock' => (int) $product->get_stock_quantity(),
            'image' => wp_get_attachment_url($product->get_image_id())
        );
        
        return $product_data;
    }
    
    /**
     * Send data to Partjoo API
     *
     * @param array $products Products data
     * @return bool Success status
     */
    private function send_to_partjoo($products) {
        $api_url = 'https://partjoo.com/partjoo/apiv1';
        
        $body = array(
            'route' => 'crawler/addProductsToPartjoo',
            'content' => array(
                'domain' => $this->settings['domain'],
                'products' => $products
            )
        );
        
        $args = array(
            'body' => json_encode($body),
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        );
        
        $response = wp_remote_post($api_url, $args);
        
        if (is_wp_error($response)) {
            // Log error
            error_log('Partjoo Sync Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            // Log error
            error_log('Partjoo Sync Error: API returned status code ' . $response_code . ' with message: ' . $response_body);
            return false;
        }
        
        return true;
    }
}
