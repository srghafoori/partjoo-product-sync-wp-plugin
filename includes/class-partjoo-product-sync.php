<?php
if ( ! defined('ABSPATH') ) { exit; }

class PartJoo_Product_Sync {

    private static $instance = null;

    const DEFAULT_ENDPOINT = 'https://partjoo.com/partjoo/apiv1';
    const ROUTE            = 'crawler/addProductsToPartjoo';

    private $opts = [];
    private $api_client;
    private $logger;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    public static function defaults() {
        return PartJoo_Config::defaults();
    }

    private function __construct() {
        $container        = PartJoo_Container::instance();
        $this->opts       = $container->get( PartJoo_Container::CONFIG )->all();
        $this->api_client = $container->get( PartJoo_Container::API_CLIENT );
        $this->logger     = $container->get( PartJoo_Container::LOGGER );

        // Keep signatures current on save
        add_action('save_post_product',           [$this, 'on_product_save'], 90, 2);
        add_action('save_post_product_variation', [$this, 'on_product_save'], 90, 2);

        // Deletion hook -> send tombstone
        add_action('before_delete_post', [$this, 'on_product_delete'], 10, 1);

        // Auto-send on save if changed
        add_action('save_post_product',           [$this, 'maybe_sync_on_save'], 99, 2);
        add_action('save_post_product_variation', [$this, 'maybe_sync_on_save'], 99, 2);

        // Real-time stock/price events (mark dirty + optional instant send)
        add_action('woocommerce_product_set_stock',        [$this, 'on_stock_obj_change']);
        add_action('woocommerce_variation_set_stock',      [$this, 'on_stock_obj_change']);
        add_action('woocommerce_product_set_stock_status', [$this, 'on_stock_status_change'], 10, 3);
        add_action('woocommerce_variation_set_stock_status', [$this, 'on_stock_status_change'], 10, 3);

        add_action('woocommerce_update_product',           [$this, 'on_wc_update_product'], 10, 1);
        add_action('woocommerce_update_product_variation', [$this, 'on_wc_update_product'], 10, 1);
    }

    /* ---------- Signature management ---------- */

    public function on_product_save($post_id, $post) {
        if ( wp_is_post_revision($post_id) ) return;
        if ( get_post_status($post_id) !== 'publish' ) return;

        $item = $this->build_product_item($post_id);
        $sig  = $this->make_signature($item);
        update_post_meta($post_id, '_partjoo_sig_current', $sig);
    }

    public function on_wc_update_product($product_id) {
        if ( get_post_status($product_id) !== 'publish' ) return;
        $item = $this->build_product_item($product_id);
        $sig  = $this->make_signature($item);
        update_post_meta($product_id, '_partjoo_sig_current', $sig);

        if ( ! empty($this->opts['send_on_events']) ) {
            $this->maybe_sync_by_id($product_id, 'event');
        }
    }

    public function on_stock_obj_change($wc_stock_obj) {
        $product_id = method_exists($wc_stock_obj, 'get_id') ? (int)$wc_stock_obj->get_id() : 0;
        if ( $product_id ) $this->on_wc_update_product($product_id);
    }

    public function on_stock_status_change($product_id, $stock_status, $product) {
        if ( $product_id ) $this->on_wc_update_product((int)$product_id);
    }

    /* ---------- Deletion ---------- */

    public function on_product_delete($post_id) {
        $type = get_post_type($post_id);
        if ( $type !== 'product' && $type !== 'product_variation' ) return;

        $p = wc_get_product($post_id);
        if ( ! $p ) return;

        $domain = trim((string)$this->opts['domain']);
        if ( $domain === '' ) return; // cannot send without domain

        $is_var = $p->is_type('variation');
        $title  = $p->get_name();
        if ($is_var) {
            $attrs = wc_get_formatted_variation($p, true, false, true);
            if ($attrs) $title .= ' - ' . wp_strip_all_tags($attrs);
        }
        $url = get_permalink($is_var ? $p->get_parent_id() : $post_id);

        // Tombstone: mark unavailable
        $item = [
            'title'        => (string)$title,
            'url'          => (string)$url,
            'availability' => "-1",
            'stock'        => 0,
        ];

        $payload = [
            'route' => self::ROUTE,
            'content' => [
                'domain' => $domain,
                'allLinks' => [],
                'products' => [ $item ],
            ],
        ];
        $this->send_payload($payload, 'delete');
    }

    /* ---------- Sending ---------- */

    public function maybe_sync_on_save($post_id, $post) {
        if ( wp_is_post_revision($post_id) ) return;
        if ( empty($this->opts['send_on_save']) ) return;
        if ( get_post_status($post_id) !== 'publish' ) return;
        $this->maybe_sync_by_id($post_id, 'single');
    }

    private function maybe_sync_by_id($post_id, $context='single') {
        $sig_current = get_post_meta($post_id, '_partjoo_sig_current', true);
        $sig_sent    = get_post_meta($post_id, '_partjoo_sig_sent', true);
        if ( ! $sig_current || $sig_current !== $sig_sent ) {
            $this->sync_products([$post_id], $context);
        }
    }

    public function sync_changed_products($context='cron', $force=false) {
        $ids = get_posts([
            'post_type'      => ['product'],
            'post_status'    => ['publish'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        if ( ! is_array($ids) ) $ids = [];
        if ( ! empty($this->opts['send_variations']) ) {
            $var_ids = get_posts([
                'post_type'      => ['product_variation'],
                'post_status'    => ['publish'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
            if ( is_array($var_ids) ) $ids = array_merge($ids, $var_ids);
        }

        $dirty = [];
        foreach ($ids as $pid) {
            $sig_c = get_post_meta($pid, '_partjoo_sig_current', true);
            $sig_s = get_post_meta($pid, '_partjoo_sig_sent', true);
            if ( $force || ! $sig_s || $sig_c !== $sig_s ) $dirty[] = $pid;
        }
        return $this->sync_products($dirty, $context, $force);
    }

    public function sync_products(array $product_ids, $context='bulk', $force=false) {
        $domain = trim((string)$this->opts['domain']);
        if ( $domain === '' ) {
            PartJoo_State::instance()->save_last_status([
                'time' => current_time('mysql'),
                'ok'   => false,
                'msg'  => 'Missing required domain',
            ]);
            return false;
        }

        $ids = array_values(array_unique(array_filter($product_ids)));
        if ( empty($ids) ) return true;

        $batch_size = max(1, min(100, (int)$this->opts['batch_size']));
        $chunks = array_chunk($ids, $batch_size);

        $all_ok = true;
        foreach ($chunks as $chunk) {
            $payload = $this->build_payload($domain, $chunk, $force);
            $res     = $this->send_payload($payload, $context);

            $ok = $this->is_response_ok($res);
            $all_ok = $all_ok && $ok;

            foreach ($chunk as $pid) {
                $item = $this->build_product_item($pid);
                $sig  = $this->make_signature($item);
                $is_var = (get_post_type($pid) === 'product_variation');
                $phash = sha1( wp_json_encode($payload) );

                $this->logger->log_product_sync($pid, $is_var ? 1 : 0, $sig, $phash, $res, $context, 1);
                if ( $ok ) {
                    update_post_meta($pid, '_partjoo_sig_sent', $sig);
                }
            }
            usleep(150000);
        }
        return $all_ok;
    }

    private function build_payload(string $domain, array $product_ids, $force=false) : array {
        $products = [];
        foreach ($product_ids as $pid) {
            $item = $this->build_product_item($pid);
            if ( ! $item ) continue;
            if ( ! $force ) {
                $sig_c = $this->make_signature($item);
                $sig_s = get_post_meta($pid, '_partjoo_sig_sent', true);
                if ( $sig_s && $sig_c === $sig_s ) continue;
            }
            $products[] = $item;
        }
        return [
            'route'   => self::ROUTE,
            'content' => [
                'domain'   => $domain,
                'allLinks' => [],
                'products' => array_values($products),
            ],
        ];
    }

    public function build_product_item(int $product_id) {
        $p = wc_get_product($product_id);
        if ( ! $p ) return null;
        if ( get_post_status($product_id) !== 'publish' ) return null;

        $is_var = $p->is_type('variation');
        $title  = $p->get_name();
        if ($is_var) {
            $attrs = wc_get_formatted_variation($p, true, false, true);
            if ($attrs) $title .= ' - ' . wp_strip_all_tags($attrs);
        }
        $url = get_permalink($is_var ? $p->get_parent_id() : $product_id);

        $image_url = '';
        $img_id = $p->get_image_id();
        if ($img_id) {
            $src = wp_get_attachment_image_src($img_id, 'full');
            if ($src && !empty($src[0])) $image_url = $src[0];
        }

        $price = $p->get_price();
        $price = is_numeric($price) ? (float)$price : null;
        $unit  = $this->opts['force_unit'];
        if (!empty($this->opts['convert_toman_rial'])) {
            if ($price !== null) $price *= 10.0;
            $unit = 'rial';
        }

        $manage_stock = (bool)$p->get_manage_stock();
        $qty = $manage_stock ? (int)$p->get_stock_quantity() : 0;
        $instock = $p->is_in_stock();
        $availability = $instock ? "1" : "-1";
        $stock = $manage_stock ? max(0, $qty) : 0;

        $cond_meta = get_post_meta($product_id, '_partjoo_condition', true);
        $condition = $cond_meta !== '' ? $cond_meta : ($this->opts['default_condition'] ?: 'new');

        $part_name   = get_post_meta($product_id, '_partjoo_part_name', true);
        $part_number = get_post_meta($product_id, '_partjoo_part_number', true);
        if (!$part_number) $part_number = $p->get_sku();

        $desc = $p->get_short_description();
        if (!$desc) $desc = $p->get_description();
        $desc = wp_strip_all_tags($desc);
        if (mb_strlen($desc) > 3000) $desc = mb_substr($desc, 0, 3000);

        $bulk_json = get_post_meta($product_id, '_partjoo_bulk_prices', true);
        $bulk_arr  = [];
        if ($bulk_json) {
            $decoded = json_decode($bulk_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    $q  = isset($row['quantity']) ? (int)$row['quantity'] : null;
                    $pr = isset($row['price']) ? (float)$row['price'] : null;
                    if ($q && $pr !== null) {
                        if (!empty($this->opts['convert_toman_rial'])) $pr *= 10.0;
                        $bulk_arr[] = ['quantity' => $q, 'price' => (int)round($pr)];
                    }
                }
            }
        }
        $bulk_arr = apply_filters('partjoo_bulk_prices', $bulk_arr, $p);

        $item = [
            'title'        => (string)$title,
            'url'          => (string)$url,
            'availability' => $availability,
        ];
        if ($image_url)                   $item['image']       = $image_url;
        if ($price !== null)              $item['price']       = (int)round($price);
        if ($unit)                        $item['unit']        = (string)$unit;
        if ($stock > 0 || $manage_stock)  $item['stock']       = (int)$stock;
        if ($condition)                   $item['condition']   = (string)$condition;
        if ($part_number)                 $item['partNumber']  = (string)$part_number;
        if ($part_name)                   $item['partName']    = (string)$part_name;
        if ($desc)                        $item['description'] = (string)$desc;
        if (!empty($bulk_arr))            $item['bulkPrices']  = $bulk_arr;

        $item = apply_filters('partjoo_product_data', $item, $p);
        return $item;
    }

    private function make_signature($item=null) {
        if (empty($item)) return '';
        $normalized = wp_json_encode($item, JSON_UNESCAPED_UNICODE);
        return sha1($normalized ?: '');
    }

    private function send_payload(array $payload, $context='bulk') {
        $response = $this->api_client->send( $payload );
        $this->logger->save_last_status( $response, $this->is_response_ok( $response ) );
        do_action('partjoo_sync_response', $response, $payload, $context);
        return $response;
    }

    private function is_response_ok($res) : bool {
        if ( is_wp_error($res) ) return false;
        $code = (int) wp_remote_retrieve_response_code($res);
        return $code >= 200 && $code < 300;
    }
}
