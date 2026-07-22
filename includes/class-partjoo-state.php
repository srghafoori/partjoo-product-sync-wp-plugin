<?php
if ( ! defined('ABSPATH') ) { exit; }

class PartJoo_State {
    private static $instance = null;
    private $table;
    const OPTS_KEY = 'partjoo_settings';
    const LAST_STATUS_OPT = 'partjoo_last_sync_status';
    const DB_VERSION_OPT = 'partjoo_db_version';
    const DB_VERSION = '1.0';

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'partjoo_sync_log';
    }

    public function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            is_variation TINYINT(1) NOT NULL DEFAULT 0,
            signature VARCHAR(64) NOT NULL,
            payload_hash VARCHAR(64) NOT NULL,
            status_code INT NOT NULL DEFAULT 0,
            status_ok TINYINT(1) NOT NULL DEFAULT 0,
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
            message TEXT NULL,
            context VARCHAR(24) NOT NULL DEFAULT 'bulk',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY product_idx (product_id),
            KEY sig_idx (signature),
            KEY created_idx (created_at)
        ) $charset;";
        dbDelta($sql);
        update_option(self::DB_VERSION_OPT, self::DB_VERSION);

        // Install queue table for Version 2.0
        $queue_repo = new PartJoo_Queue_Repository();
        $queue_repo->install();
    }

    public function table() { return $this->table; }

    public function get_options() {
        return wp_parse_args( get_option(self::OPTS_KEY, []), PartJoo_Product_Sync::defaults() );
    }

    public function put_log($product_id, $is_variation, $signature, $payload_hash, $response, $context='bulk', $attempt=1) {
        global $wpdb;
        $ok = false; $code = 0; $msg = '';
        if ( is_wp_error($response) ) {
            $msg = $response->get_error_message();
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $ok   = ($code >= 200 && $code < 300);
            $body = wp_remote_retrieve_body($response);
            $msg  = is_string($body) ? mb_substr($body, 0, 1000) : '';
        }
        $wpdb->insert($this->table, [
            'product_id'  => (int)$product_id,
            'is_variation'=> (int)$is_variation,
            'signature'   => (string)$signature,
            'payload_hash'=> (string)$payload_hash,
            'status_code' => $code,
            'status_ok'   => $ok ? 1 : 0,
            'attempt'     => (int)$attempt,
            'message'     => $msg,
            'context'     => $context,
            'created_at'  => current_time('mysql'),
        ]);
    }

    public function get_recent_logs($limit=20) {
        global $wpdb;
        $limit = max(1, min(200, (int)$limit));
        return $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY id DESC LIMIT {$limit}", ARRAY_A);
    }

}
