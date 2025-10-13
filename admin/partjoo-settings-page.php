<?php
/**
 * Settings page template
 *
 * @package Partjoo_Product_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display settings page
 */
function partjoo_settings_page() {
    // Process manual sync request
    if (isset($_GET['action']) && $_GET['action'] === 'sync_now' && check_admin_referer('partjoo_sync_now')) {
        $sync_instance = Partjoo_Product_Sync::get_instance();
        $result = $sync_instance->sync_all_products();
        
        if ($result) {
            add_settings_error(
                'partjoo_sync_messages',
                'partjoo_sync_success',
                __('همگام‌سازی محصولات با موفقیت انجام شد.', 'partjoo-sync'),
                'success'
            );
        } else {
            add_settings_error(
                'partjoo_sync_messages',
                'partjoo_sync_error',
                __('خطا در همگام‌سازی محصولات. لطفاً لاگ‌ها را بررسی کنید.', 'partjoo-sync'),
                'error'
            );
        }
    }
    
    // Display settings
    ?>
    <div class="wrap">
        <h1><?php _e('تنظیمات همگام‌سازی محصولات با پارتجو', 'partjoo-sync'); ?></h1>
        
        <?php settings_errors('partjoo_sync_messages'); ?>
        
        <form method="post" action="options.php">
            <?php
            settings_fields('partjoo_sync_settings_group');
            do_settings_sections('partjoo-sync-settings');
            submit_button(__('ذخیره تنظیمات', 'partjoo-sync'));
            ?>
        </form>
        
        <hr>
        
        <h2><?php _e('همگام‌سازی دستی', 'partjoo-sync'); ?></h2>
        <p><?php _e('برای همگام‌سازی دستی تمام محصولات با پارتجو، دکمه زیر را کلیک کنید.', 'partjoo-sync'); ?></p>
        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=partjoo-sync-settings&action=sync_now'), 'partjoo_sync_now'); ?>" class="button button-primary"><?php _e('همگام‌سازی اکنون', 'partjoo-sync'); ?></a>
    </div>
    <?php
}
