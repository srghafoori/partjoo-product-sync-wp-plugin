<?php
/**
 * Admin functionality
 *
 * @package Partjoo_Product_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin class
 */
class Partjoo_Admin {
    
    // Plugin settings
    private $settings;
    
    /**
     * Class constructor
     *
     * @param array $settings Plugin settings
     */
    public function __construct($settings) {
        $this->settings = $settings;
        
        // Load settings page
        require_once PARTJOO_SYNC_PLUGIN_DIR . 'admin/partjoo-settings-page.php';
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add settings link
        add_filter('plugin_action_links_' . PARTJOO_SYNC_PLUGIN_BASENAME, array($this, 'add_settings_link'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('تنظیمات همگام‌سازی پارتجو', 'partjoo-sync'),
            __('همگام‌سازی پارتجو', 'partjoo-sync'),
            'manage_options',
            'partjoo-sync-settings',
            'partjoo_settings_page'
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'partjoo_sync_settings_group',
            'partjoo_sync_settings',
            array($this, 'sanitize_settings')
        );
        
        add_settings_section(
            'partjoo_sync_main_section',
            __('تنظیمات اصلی', 'partjoo-sync'),
            array($this, 'main_section_callback'),
            'partjoo-sync-settings'
        );
        
        add_settings_field(
            'partjoo_sync_domain',
            __('دامنه پارتجو', 'partjoo-sync'),
            array($this, 'domain_callback'),
            'partjoo-sync-settings',
            'partjoo_sync_main_section'
        );
        
        add_settings_field(
            'partjoo_sync_auto_sync',
            __('همگام‌سازی خودکار', 'partjoo-sync'),
            array($this, 'auto_sync_callback'),
            'partjoo-sync-settings',
            'partjoo_sync_main_section'
        );
        
        add_settings_field(
            'partjoo_sync_interval',
            __('فاصله زمانی همگام‌سازی', 'partjoo-sync'),
            array($this, 'sync_interval_callback'),
            'partjoo-sync-settings',
            'partjoo_sync_main_section'
        );
    }
    
    /**
     * Main section description
     */
    public function main_section_callback() {
        echo '<p>' . __('تنظیمات مربوط به همگام‌سازی محصولات با موتور جستجوی پارتجو را وارد کنید.', 'partjoo-sync') . '</p>';
    }
    
    /**
     * Domain field callback
     */
    public function domain_callback() {
        $domain = isset($this->settings['domain']) ? $this->settings['domain'] : '';
        echo '<input type="text" id="partjoo_sync_domain" name="partjoo_sync_settings[domain]" value="' . esc_attr($domain) . '" class="regular-text" />';
        echo '<p class="description">' . __('دامنه اختصاصی خود را که از تیم فنی پارتجو دریافت کرده‌اید وارد کنید.', 'partjoo-sync') . '</p>';
    }
    
    /**
     * Auto sync field callback
     */
    public function auto_sync_callback() {
        $auto_sync = isset($this->settings['auto_sync']) ? $this->settings['auto_sync'] : 'yes';
        echo '<select id="partjoo_sync_auto_sync" name="partjoo_sync_settings[auto_sync]">';
        echo '<option value="yes" ' . selected($auto_sync, 'yes', false) . '>' . __('فعال', 'partjoo-sync') . '</option>';
        echo '<option value="no" ' . selected($auto_sync, 'no', false) . '>' . __('غیرفعال', 'partjoo-sync') . '</option>';
        echo '</select>';
    }
    
    /**
     * Sync interval field callback
     */
    public function sync_interval_callback() {
        $interval = isset($this->settings['sync_interval']) ? $this->settings['sync_interval'] : 'daily';
        echo '<select id="partjoo_sync_interval" name="partjoo_sync_settings[sync_interval]">';
        echo '<option value="hourly" ' . selected($interval, 'hourly', false) . '>' . __('هر ساعت', 'partjoo-sync') . '</option>';
        echo '<option value="twicedaily" ' . selected($interval, 'twicedaily', false) . '>' . __('دو بار در روز', 'partjoo-sync') . '</option>';
        echo '<option value="daily" ' . selected($interval, 'daily', false) . '>' . __('روزانه', 'partjoo-sync') . '</option>';
        echo '<option value="weekly" ' . selected($interval, 'weekly', false) . '>' . __('هفتگی', 'partjoo-sync') . '</option>';
        echo '</select>';
    }
    
    /**
     * Sanitize settings
     *
     * @param array $input Input settings
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
        $new_input = array();
        
        $new_input['domain'] = sanitize_text_field($input['domain']);
        $new_input['auto_sync'] = ($input['auto_sync'] === 'yes') ? 'yes' : 'no';
        
        $valid_intervals = array('hourly', 'twicedaily', 'daily', 'weekly');
        $new_input['sync_interval'] = in_array($input['sync_interval'], $valid_intervals) ? $input['sync_interval'] : 'daily';
        
        // Check for changes in schedule settings
        $old_settings = get_option('partjoo_sync_settings', array());
        
        if ($new_input['auto_sync'] !== $old_settings['auto_sync'] || $new_input['sync_interval'] !== $old_settings['sync_interval']) {
            // Clear previous schedule
            wp_clear_scheduled_hook('partjoo_sync_cron_hook');
            
            // Create new schedule if auto sync is enabled
            if ($new_input['auto_sync'] === 'yes') {
                wp_schedule_event(time(), $new_input['sync_interval'], 'partjoo_sync_cron_hook');
            }
        }
        
        return $new_input;
    }
    
    /**
     * Add settings link
     *
     * @param array $links Plugin links
     * @return array Modified links
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=partjoo-sync-settings') . '">' . __('تنظیمات', 'partjoo-sync') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_assets($hook) {
        if ('woocommerce_page_partjoo-sync-settings' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'partjoo-admin-css',
            PARTJOO_SYNC_PLUGIN_URL . 'assets/css/partjoo-admin.css',
            array(),
            PARTJOO_SYNC_VERSION
        );
        
        wp_enqueue_script(
            'partjoo-admin-js',
            PARTJOO_SYNC_PLUGIN_URL . 'assets/js/partjoo-admin.js',
            array('jquery'),
            PARTJOO_SYNC_VERSION,
            true
        );
    }
}
