<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Logger {

    private $state;
    const LAST_STATUS_OPT = 'partjoo_last_sync_status';

    public function __construct( PartJoo_State $state ) {
        $this->state = $state;
    }

    /**
     * Save last sync status directly from an array
     */
    public function save_last_status(array $status) {
        update_option(self::LAST_STATUS_OPT, $status, false);
    }
    
    /**
     * Record HTTP response status
     */
    public function record_http_status($response, bool $ok) {
        update_option(self::LAST_STATUS_OPT, [
            'time'    => current_time( 'mysql' ),
            'ok'      => $ok,
            'code'    => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ),
            'message' => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ),
        ], false);
    }
    
    /**
     * Get last sync status using the option key
     */
    public function get_last_status() {
        return get_option(self::LAST_STATUS_OPT);
    }

    /**
     * @param int            $product_id   Product ID.
     * @param bool           $is_variation Whether the product is a variation.
     * @param string         $signature    Product signature.
     * @param string         $payload_hash Payload hash.
     * @param array|WP_Error $response     HTTP response.
     * @param string         $context      Sync context.
     * @param int            $attempt      Attempt number.
     */
    public function log_product_sync( $product_id, $is_variation, $signature, $payload_hash, $response, $context = 'bulk', $attempt = 1 ) {
        $this->state->put_log( $product_id, $is_variation ? 1 : 0, $signature, $payload_hash, $response, $context, $attempt );
    }

    public function log_validation_error( $product_id, $is_variation, $signature, $payload_hash, $message, $context = 'bulk' ) {
        $response = new WP_Error( 'partjoo_validation_failed', $message );
        $this->log_product_sync( $product_id, $is_variation, $signature, $payload_hash, $response, $context, 1 );
    }
}