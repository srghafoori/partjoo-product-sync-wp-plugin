<?php
if ( ! defined('ABSPATH') ) { exit; }

class PartJoo_Admin {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_menu',          [$this, 'add_menu']);
        add_action('admin_init',          [$this, 'register_settings']);
        add_action('add_meta_boxes',      [$this, 'add_product_metabox']);
        add_action('save_post_product',   [$this, 'save_product_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);

        add_action('admin_post_partjoo_manual_sync',          [$this, 'handle_manual_sync']);
        add_action('admin_post_partjoo_manual_force',         [$this, 'handle_manual_force']);
        add_action('admin_post_partjoo_recalc_signatures',    [$this, 'handle_recalc_signatures']);
        add_action('update_option_' . PartJoo_State::OPTS_KEY, [$this, 'maybe_reschedule_cron'], 10, 2);
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __('PartJoo Sync', 'partjoo-product-sync'),
            __('PartJoo Sync', 'partjoo-product-sync'),
            'manage_woocommerce',
            'partjoo-sync',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('partjoo_settings_group', PartJoo_State::OPTS_KEY, [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);

        add_settings_section('partjoo_main', __('Main Settings', 'partjoo-product-sync'), '__return_false', 'partjoo_sync');

        $fields = [
            ['endpoint', 'text',    __('API Endpoint', 'partjoo-product-sync'), PartJoo_Product_Sync::DEFAULT_ENDPOINT],
            ['api_key',  'text',    __('API Key (optional)', 'partjoo-product-sync'), ''],
            ['domain',   'text',    __('Assigned Domain (Required)', 'partjoo-product-sync'), ''],
            ['batch_size','number', __('Batch Size (1-100)', 'partjoo-product-sync'), 100],
            ['send_on_save','checkbox', __('Send on Save/Update', 'partjoo-product-sync'), 1],
            ['send_on_events','checkbox', __('Send on Stock/Price Events', 'partjoo-product-sync'), 1],
            ['convert_toman_rial','checkbox', __('Convert Toman → Rial (×10)', 'partjoo-product-sync'), 1],
            ['force_unit','select', __('Unit to send', 'partjoo-product-sync'), 'rial', ['','rial','toman','dollar','yuan']],
            ['default_condition','select', __('Default Condition', 'partjoo-product-sync'), 'new', ['new','oem','copy','renew','used','nos']],
            ['send_variations','checkbox', __('Send Variations Separately', 'partjoo-product-sync'), 0],
            ['cron_recurrence','select', __('Cron Recurrence', 'partjoo-product-sync'), 'hourly', ['hourly','twicedaily','daily']],
        ];

        foreach ($fields as $f) {
            add_settings_field(
                $f[0], $f[2],
                function () use ($f) { $this->render_field($f); },
                'partjoo_sync', 'partjoo_main'
            );
        }
    }

    public function sanitize_settings($in) {
        $out = wp_parse_args($in, []);
        $out['endpoint']           = esc_url_raw($out['endpoint'] ?? PartJoo_Product_Sync::DEFAULT_ENDPOINT);
        $out['api_key']            = sanitize_text_field($out['api_key'] ?? '');
        $out['domain']             = sanitize_text_field($out['domain'] ?? '');
        $out['batch_size']         = max(1, min(100, (int)($out['batch_size'] ?? 100)));
        $out['send_on_save']       = !empty($out['send_on_save']) ? 1 : 0;
        $out['send_on_events']     = !empty($out['send_on_events']) ? 1 : 0;
        $out['convert_toman_rial'] = !empty($out['convert_toman_rial']) ? 1 : 0;
        $out['force_unit']         = in_array(($out['force_unit'] ?? 'rial'), ['', 'rial','toman','dollar','yuan'], true) ? $out['force_unit'] : 'rial';
        $out['default_condition']  = in_array(($out['default_condition'] ?? 'new'), ['new','oem','copy','renew','used','nos'], true) ? $out['default_condition'] : 'new';
        $out['send_variations']    = !empty($out['send_variations']) ? 1 : 0;
        $out['cron_recurrence']    = in_array(($out['cron_recurrence'] ?? 'hourly'), ['hourly','twicedaily','daily'], true) ? $out['cron_recurrence'] : 'hourly';
        return $out;
    }

    private function render_field($f) {
        list($key, $type, $label, $def) = $f;
        $opts = get_option(PartJoo_State::OPTS_KEY, []);
        $val  = isset($opts[$key]) ? $opts[$key] : $def;

        if ($type === 'text') {
            echo '<input type="text" class="regular-text" name="'.PartJoo_State::OPTS_KEY.'['.$key.']" value="'.esc_attr($val).'" />';
        } elseif ($type === 'number') {
            echo '<input type="number" min="1" max="100" name="'.PartJoo_State::OPTS_KEY.'['.$key.']" value="'.esc_attr($val).'" />';
        } elseif ($type === 'checkbox') {
            echo '<label><input type="checkbox" name="'.PartJoo_State::OPTS_KEY.'['.$key.']" value="1" '.checked($val, 1, false).'/> '.esc_html($label).'</label>';
        } elseif ($type === 'select') {
            $choices = $f[4] ?? [];
            echo '<select name="'.PartJoo_State::OPTS_KEY.'['.$key.']">';
            foreach ($choices as $c) {
                echo '<option value="'.esc_attr($c).'" '.selected($val, $c, false).'>'.esc_html($c).'</option>';
            }
            echo '</select>';
        }
    }

    public function render_settings_page() {
        $container = PartJoo_Container::instance();
        $logger = $container->get(PartJoo_Container::LOGGER);
        $products = $container->get(PartJoo_Container::PRODUCT_REPOSITORY);
        
        $status = $logger->get_last_status();
        $dirty  = $products->count_dirty_products();
        $logs   = PartJoo_State::instance()->get_recent_logs(20);
        $opts   = get_option(PartJoo_State::OPTS_KEY, []);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('PartJoo Sync', 'partjoo-product-sync'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('partjoo_settings_group');
                do_settings_sections('partjoo_sync');
                submit_button();
                ?>
            </form>

            <hr/>
            <h2><?php esc_html_e('Operations', 'partjoo-product-sync'); ?></h2>
            <p><strong><?php esc_html_e('Dirty products', 'partjoo-product-sync'); ?>:</strong> <?php echo esc_html((string)$dirty); ?></p>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('partjoo_manual_sync'); ?>
                    <input type="hidden" name="action" value="partjoo_manual_sync">
                    <?php submit_button(__('Sync CHANGED products now', 'partjoo-product-sync'), 'primary', '', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('partjoo_manual_force'); ?>
                    <input type="hidden" name="action" value="partjoo_manual_force">
                    <?php submit_button(__('Force RESEND ALL (max per batch applies)', 'partjoo-product-sync'), 'secondary', '', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('partjoo_recalc_signatures'); ?>
                    <input type="hidden" name="action" value="partjoo_recalc_signatures">
                    <?php submit_button(__('Recalculate signatures', 'partjoo-product-sync'), 'secondary', '', false); ?>
                </form>
            </div>

            <hr/>
            <h2><?php esc_html_e('Last Sync Status', 'partjoo-product-sync'); ?></h2>
            <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;max-height:240px;overflow:auto;"><?php echo esc_html( print_r($status, true) ); ?></pre>

            <hr/>
            <h2><?php esc_html_e('Recent Logs', 'partjoo-product-sync'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'partjoo-product-sync'); ?></th>
                        <th><?php esc_html_e('Product', 'partjoo-product-sync'); ?></th>
                        <th><?php esc_html_e('Var?', 'partjoo-product-sync'); ?></th>
                        <th><?php esc_html_e('Code', 'partjoo-product-sync'); ?></th>
                        <th><?php esc_html_e('OK', 'partjoo-product-sync'); ?></th>
                        <th><?php esc_html_e('Context', 'partjoo-product-sync'); ?></th>
                        <th><?php esc_html_e('Created', 'partjoo-product-sync'); ?></th>
                        <th><?php esc_html_e('Message', 'partjoo-product-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $row): ?>
                    <tr>
                        <td><?php echo (int)$row['id']; ?></td>
                        <td><a href="<?php echo esc_url(get_edit_post_link($row['product_id'])); ?>" target="_blank"><?php echo (int)$row['product_id']; ?></a></td>
                        <td><?php echo $row['is_variation'] ? 'Y' : 'N'; ?></td>
                        <td><?php echo (int)$row['status_code']; ?></td>
                        <td><?php echo $row['status_ok'] ? '✔' : '✖'; ?></td>
                        <td><?php echo esc_html($row['context']); ?></td>
                        <td><?php echo esc_html($row['created_at']); ?></td>
                        <td style="max-width:480px;white-space:pre-wrap;"><?php echo esc_html($row['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function add_product_metabox() {
        foreach (['product','product_variation'] as $pt) {
            add_meta_box(
                'partjoo_product_box_'.$pt,
                __('PartJoo Fields', 'partjoo-product-sync'),
                [$this, 'render_product_metabox'],
                [$pt],
                'side',
                'default'
            );
        }
    }

    public function render_product_metabox($post) {
        wp_nonce_field('partjoo_product_meta', 'partjoo_product_meta_nonce');

        $pn = get_post_meta($post->ID, '_partjoo_part_number', true);
        $pm = get_post_meta($post->ID, '_partjoo_part_name', true);
        $co = get_post_meta($post->ID, '_partjoo_condition', true);
        $bp = get_post_meta($post->ID, '_partjoo_bulk_prices', true);
        ?>
        <p><label><?php esc_html_e('Part Number', 'partjoo-product-sync'); ?><br/>
            <input type="text" name="partjoo_part_number" value="<?php echo esc_attr($pn); ?>" style="width:100%"/></label></p>
        <p><label><?php esc_html_e('Part Name', 'partjoo-product-sync'); ?><br/>
            <input type="text" name="partjoo_part_name" value="<?php echo esc_attr($pm); ?>" style="width:100%"/></label></p>
        <p><label><?php esc_html_e('Condition', 'partjoo-product-sync'); ?><br/>
            <select name="partjoo_condition" style="width:100%">
                <?php $conds = ['','new','oem','copy','renew','used','nos'];
                foreach ($conds as $c) printf('<option value="%s"%s>%s</option>', esc_attr($c), selected($co,$c,false), esc_html($c ?: '(default)')); ?>
            </select></label></p>
        <p><label><?php esc_html_e('Bulk Prices (JSON)', 'partjoo-product-sync'); ?><br/>
            <textarea name="partjoo_bulk_prices" rows="5" style="width:100%" placeholder='[{"quantity":10,"price":950000},{"quantity":100,"price":900000}]'><?php echo esc_textarea($bp); ?></textarea>
        </label></p>

        <p>
            <button type="button" class="button button-primary partjoo-sync-now" data-id="<?php echo (int)$post->ID; ?>"><?php esc_html_e('Sync this item', 'partjoo-product-sync'); ?></button>
            <button type="button" class="button partjoo-sync-now" data-force="1" data-id="<?php echo (int)$post->ID; ?>"><?php esc_html_e('Force resend', 'partjoo-product-sync'); ?></button>
        </p>
        <?php
    }

    public function save_product_meta($post_id) {
        if ( ! isset($_POST['partjoo_product_meta_nonce']) ) return;
        if ( ! wp_verify_nonce($_POST['partjoo_product_meta_nonce'], 'partjoo_product_meta') ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can('edit_post', $post_id) ) return;

        $pn = sanitize_text_field($_POST['partjoo_part_number'] ?? '');
        $pm = sanitize_text_field($_POST['partjoo_part_name'] ?? '');
        $co = sanitize_text_field($_POST['partjoo_condition'] ?? '');
        $bp = wp_unslash($_POST['partjoo_bulk_prices'] ?? '');

        update_post_meta($post_id, '_partjoo_part_number', $pn);
        update_post_meta($post_id, '_partjoo_part_name', $pm);
        update_post_meta($post_id, '_partjoo_condition', $co);

        if ($bp !== '') {
            json_decode($bp, true);
            if ( json_last_error() === JSON_ERROR_NONE ) {
                update_post_meta($post_id, '_partjoo_bulk_prices', $bp);
            } else {
                delete_post_meta($post_id, '_partjoo_bulk_prices');
            }
        } else {
            delete_post_meta($post_id, '_partjoo_bulk_prices');
        }
    }

    public function enqueue($hook) {
        if ( $hook !== 'woocommerce_page_partjoo-sync' && ! in_array($hook, ['post.php','post-new.php'], true) ) return;
        wp_enqueue_script('partjoo-admin-js', PARTJOO_PLUGIN_URL.'assets/js/partjoo-admin.js', ['jquery'], PARTJOO_PLUGIN_VERSION, true);
        wp_localize_script('partjoo-admin-js', 'PartJooAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('partjoo_sync_nonce'),
        ]);
        wp_enqueue_style('partjoo-admin-css', PARTJOO_PLUGIN_URL.'assets/css/partjoo-admin.css', [], PARTJOO_PLUGIN_VERSION);
    }

    public function handle_manual_sync() {
        check_admin_referer('partjoo_manual_sync');
        if ( ! current_user_can('manage_woocommerce') ) wp_die('No permission');
        PartJoo_Product_Sync::instance()->sync_changed_products('admin', false);
        wp_redirect( admin_url('admin.php?page=partjoo-sync') );
        exit;
    }

    public function handle_manual_force() {
        check_admin_referer('partjoo_manual_force');
        if ( ! current_user_can('manage_woocommerce') ) wp_die('No permission');
        PartJoo_Product_Sync::instance()->sync_changed_products('admin', true);
        wp_redirect( admin_url('admin.php?page=partjoo-sync') );
        exit;
    }

    public function handle_recalc_signatures() {
        check_admin_referer('partjoo_recalc_signatures');
        if ( ! current_user_can('manage_woocommerce') ) wp_die('No permission');
        $ids = get_posts([
            'post_type'      => ['product','product_variation'],
            'post_status'    => ['publish'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        foreach ($ids as $pid) {
            $item = PartJoo_Product_Sync::instance()->build_product_item($pid);
            $sig  = $item ? sha1( wp_json_encode($item, JSON_UNESCAPED_UNICODE) ) : '';
            update_post_meta($pid, '_partjoo_sig_current', $sig);
        }
        wp_redirect( admin_url('admin.php?page=partjoo-sync') );
        exit;
    }

    public function maybe_reschedule_cron($old, $new) {
        $old_rec = $old['cron_recurrence'] ?? 'hourly';
        $new_rec = $new['cron_recurrence'] ?? 'hourly';
        if ( $old_rec !== $new_rec ) {
            $ts = wp_next_scheduled('partjoo_cron_sync_changed');
            if ( $ts ) wp_unschedule_event($ts, 'partjoo_cron_sync_changed');
            wp_schedule_event(time() + 60, $new_rec, 'partjoo_cron_sync_changed');
        }
    }
}