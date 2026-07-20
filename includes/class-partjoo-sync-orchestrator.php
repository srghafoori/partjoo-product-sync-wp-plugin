<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Sync_Orchestrator {

    private $config;
    private $products;
    private $payload_builder;
    private $signatures;
    private $payload_validator;
    private $api_client;
    private $logger;
    private $state;

    public function __construct( PartJoo_Config $config, PartJoo_Product_Repository $products, PartJoo_Payload_Builder $payload_builder, PartJoo_Signature_Service $signatures, PartJoo_Payload_Validator $payload_validator, PartJoo_Api_Client_Interface $api_client, PartJoo_Logger $logger, PartJoo_State $state ) {
        $this->config          = $config;
        $this->products        = $products;
        $this->payload_builder = $payload_builder;
        $this->signatures      = $signatures;
        $this->payload_validator = $payload_validator;
        $this->api_client      = $api_client;
        $this->logger          = $logger;
        $this->state           = $state;
    }

    public function maybe_sync_by_id( $product_id, $context = 'single' ) {
        if ( $this->products->is_dirty( $product_id ) ) {
            return $this->sync_products( [ $product_id ], $context );
        }

        return null;
    }

    public function sync_changed_products( $context = 'cron', $force = false ) {
        $ids   = $this->products->get_syncable_product_ids( ! empty( $this->config->get( 'send_variations' ) ) );
        $dirty = [];

        foreach ( $ids as $product_id ) {
            if ( $force || $this->products->is_dirty( $product_id ) ) {
                $dirty[] = $product_id;
            }
        }

        return $this->sync_products( $dirty, $context, $force );
    }

    public function sync_products( array $product_ids, $context = 'bulk', $force = false ) {
        $domain = trim( (string) $this->config->get( 'domain' ) );

        if ( '' === $domain ) {
            $this->state->save_last_status( [
                'time' => current_time( 'mysql' ),
                'ok'   => false,
                'msg'  => 'Missing required domain',
            ] );

            return false;
        }

        $ids = array_values( array_unique( array_filter( $product_ids ) ) );
        if ( empty( $ids ) ) {
            return true;
        }

        $batch_size = max( 1, min( 100, (int) $this->config->get( 'batch_size' ) ) );
        $chunks     = array_chunk( $ids, $batch_size );
        $all_ok     = true;

        foreach ( $chunks as $chunk ) {
            $entries  = $this->payload_builder->build_product_entries( $chunk, $force );
            $payload  = $this->payload_builder->build_payload_from_entries( $domain, $entries );
            $validation = $this->payload_validator->validate( $payload, $entries );

            if ( $validation->has_errors() ) {
                $this->log_validation_errors( $validation, $payload, $entries, $context );
                $all_ok = false;
            }

            $valid_entries = $validation->get_valid_entries();
            if ( empty( $valid_entries ) ) {
                usleep( 150000 );
                continue;
            }

            $payload  = $this->payload_builder->build_payload_from_entries( $domain, $valid_entries );
            $response = $this->dispatch_payload( $payload, $context );
            $ok       = $this->is_response_ok( $response );
            $all_ok   = $all_ok && $ok;

            foreach ( $valid_entries as $entry ) {
                $product_id   = $entry['product_id'];
                $item         = $this->payload_builder->build_product_item( $product_id );
                $signature    = $this->signatures->make( $item );
                $is_variation = 'product_variation' === $this->products->get_post_type( $product_id );
                $payload_hash = sha1( wp_json_encode( $payload ) );

                $this->logger->log_product_sync( $product_id, $is_variation, $signature, $payload_hash, $response, $context, 1 );

                if ( $ok ) {
                    update_post_meta( $product_id, '_partjoo_sig_sent', $signature );
                }
            }

            usleep( 150000 );
        }

        return $all_ok;
    }

    public function send_payload( array $payload, $context = 'bulk' ) {
        $entries = [];
        foreach ( $payload['content']['products'] ?? [] as $index => $product ) {
            $entries[] = [
                'product_id' => 0,
                'item'       => $product,
            ];
        }

        $validation = $this->payload_validator->validate( $payload, $entries );
        if ( $validation->has_errors() ) {
            $this->log_validation_errors( $validation, $payload, $entries, $context );

            return new WP_Error( 'partjoo_validation_failed', implode( ' ', reset( $validation->get_errors() ) ) );
        }

        return $this->dispatch_payload( $payload, $context );
    }

    private function dispatch_payload( array $payload, $context ) {
        $response = $this->api_client->send( $payload );
        $this->logger->save_last_status( $response, $this->is_response_ok( $response ) );

        do_action( 'partjoo_sync_response', $response, $payload, $context );

        return $response;
    }

    private function log_validation_errors( PartJoo_Validation_Result $validation, array $payload, array $entries, $context ) {
        $entry_map    = [];
        $payload_hash = sha1( wp_json_encode( $payload ) );

        foreach ( $entries as $entry ) {
            $entry_map[ $entry['product_id'] ] = $entry;
        }

        foreach ( $validation->get_errors() as $product_id => $messages ) {
            $entry        = isset( $entry_map[ $product_id ] ) ? $entry_map[ $product_id ] : [ 'item' => null ];
            $signature    = $this->signatures->make( $entry['item'] );
            $is_variation = 'product_variation' === $this->products->get_post_type( $product_id );
            $this->logger->log_validation_error( $product_id, $is_variation, $signature, $payload_hash, implode( ' ', $messages ), $context );
        }
    }

    private function is_response_ok( $response ) {
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        return $code >= 200 && $code < 300;
    }
}
