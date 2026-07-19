<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Product_Sync {

    private static $instance = null;

    const DEFAULT_ENDPOINT = 'https://partjoo.com/partjoo/apiv1';
    const ROUTE            = 'crawler/addProductsToPartjoo';

    private $opts = [];
    private $products;
    private $payload_builder;
    private $signatures;
    private $orchestrator;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function defaults() {
        return PartJoo_Config::defaults();
    }

    private function __construct() {
        $container             = PartJoo_Container::instance();
        $this->opts            = $container->get( PartJoo_Container::CONFIG )->all();
        $this->products        = $container->get( PartJoo_Container::PRODUCTS );
        $this->payload_builder = $container->get( PartJoo_Container::PAYLOADS );
        $this->signatures      = $container->get( PartJoo_Container::SIGNATURES );
        $this->orchestrator    = $container->get( PartJoo_Container::SYNC );

        add_action( 'save_post_product', [ $this, 'on_product_save' ], 90, 2 );
        add_action( 'save_post_product_variation', [ $this, 'on_product_save' ], 90, 2 );
        add_action( 'before_delete_post', [ $this, 'on_product_delete' ], 10, 1 );
        add_action( 'save_post_product', [ $this, 'maybe_sync_on_save' ], 99, 2 );
        add_action( 'save_post_product_variation', [ $this, 'maybe_sync_on_save' ], 99, 2 );
        add_action( 'woocommerce_product_set_stock', [ $this, 'on_stock_obj_change' ] );
        add_action( 'woocommerce_variation_set_stock', [ $this, 'on_stock_obj_change' ] );
        add_action( 'woocommerce_product_set_stock_status', [ $this, 'on_stock_status_change' ], 10, 3 );
        add_action( 'woocommerce_variation_set_stock_status', [ $this, 'on_stock_status_change' ], 10, 3 );
        add_action( 'woocommerce_update_product', [ $this, 'on_wc_update_product' ], 10, 1 );
        add_action( 'woocommerce_update_product_variation', [ $this, 'on_wc_update_product' ], 10, 1 );
    }

    public function on_product_save( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( 'publish' !== $this->products->get_post_status( $post_id ) ) {
            return;
        }

        $item      = $this->build_product_item( $post_id );
        $signature = $this->signatures->make( $item );
        update_post_meta( $post_id, '_partjoo_sig_current', $signature );
    }

    public function on_wc_update_product( $product_id ) {
        if ( 'publish' !== $this->products->get_post_status( $product_id ) ) {
            return;
        }

        $item      = $this->build_product_item( $product_id );
        $signature = $this->signatures->make( $item );
        update_post_meta( $product_id, '_partjoo_sig_current', $signature );

        if ( ! empty( $this->opts['send_on_events'] ) ) {
            $this->orchestrator->maybe_sync_by_id( $product_id, 'event' );
        }
    }

    public function on_stock_obj_change( $wc_stock_obj ) {
        $product_id = method_exists( $wc_stock_obj, 'get_id' ) ? (int) $wc_stock_obj->get_id() : 0;

        if ( $product_id ) {
            $this->on_wc_update_product( $product_id );
        }
    }

    public function on_stock_status_change( $product_id, $stock_status, $product ) {
        if ( $product_id ) {
            $this->on_wc_update_product( (int) $product_id );
        }
    }

    public function on_product_delete( $post_id ) {
        $type = $this->products->get_post_type( $post_id );
        if ( 'product' !== $type && 'product_variation' !== $type ) {
            return;
        }

        $product = $this->products->get_product( $post_id );
        if ( ! $product ) {
            return;
        }

        $domain = trim( (string) $this->opts['domain'] );
        if ( '' === $domain ) {
            return;
        }

        $payload = $this->payload_builder->build_deletion_payload( $product, $post_id, $domain );
        $this->orchestrator->send_payload( $payload, 'delete' );
    }

    public function maybe_sync_on_save( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( empty( $this->opts['send_on_save'] ) ) {
            return;
        }

        if ( 'publish' !== $this->products->get_post_status( $post_id ) ) {
            return;
        }

        $this->orchestrator->maybe_sync_by_id( $post_id, 'single' );
    }

    public function sync_changed_products( $context = 'cron', $force = false ) {
        return $this->orchestrator->sync_changed_products( $context, $force );
    }

    public function sync_products( array $product_ids, $context = 'bulk', $force = false ) {
        return $this->orchestrator->sync_products( $product_ids, $context, $force );
    }

    public function build_product_item( int $product_id ) {
        return $this->payload_builder->build_product_item( $product_id );
    }
}
