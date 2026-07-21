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
    private $queue_service;

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
        $this->queue_service   = $container->get( PartJoo_Container::QUEUE_SERVICE );

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
            // Enqueue product for sync instead of direct API call.
            $is_variation = 'product_variation' === $this->products->get_post_type( $product_id );
            $priority     = 5; // Higher priority for event-driven syncs.
            $context      = 'event';
            
            $queued_id = $this->queue_service->enqueue_product(
                $product_id,
                $is_variation,
                'sync',
                $priority,
                $context
            );

            // Fallback: if queue fails or is disabled, use synchronous sync.
            if ( empty( $queued_id ) && ! $this->queue_service->is_queued( $product_id, 'sync' ) ) {
                $this->orchestrator->maybe_sync_by_id( $product_id, 'event' );
            }
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

        // Enqueue deletion instead of direct API call.
        $is_variation = 'product_variation' === $type;
        $priority     = 5; // Higher priority for deletions.
        $context      = 'delete';

        $queued_id = $this->queue_service->enqueue_product(
            $post_id,
            $is_variation,
            'delete',
            $priority,
            $context
        );

        // Fallback: if queue fails, use synchronous deletion.
        if ( empty( $queued_id ) && ! $this->queue_service->is_queued( $post_id, 'delete' ) ) {
            $payload = $this->payload_builder->build_deletion_payload( $product, $post_id, $domain );
            $this->orchestrator->send_payload( $payload, 'delete' );
        }
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

        // Enqueue product for sync instead of direct API call.
        $is_variation = 'product_variation' === $this->products->get_post_type( $post_id );
        $priority     = 10; // Normal priority for save-driven syncs.
        $context      = 'single';

        $queued_id = $this->queue_service->enqueue_product(
            $post_id,
            $is_variation,
            'sync',
            $priority,
            $context
        );

        // Fallback: if queue fails or is disabled, use synchronous sync.
        if ( empty( $queued_id ) && ! $this->queue_service->is_queued( $post_id, 'sync' ) ) {
            $this->orchestrator->maybe_sync_by_id( $post_id, 'single' );
        }
    }

    public function sync_changed_products( $context = 'cron', $force = false ) {
        // Enqueue all changed products instead of syncing directly.
        $ids = $this->products->get_syncable_product_ids( ! empty( $this->opts['send_variations'] ) );
        
        if ( empty( $ids ) ) {
            return true;
        }

        $dirty = [];
        foreach ( $ids as $product_id ) {
            if ( $force || $this->products->is_dirty( $product_id ) ) {
                $dirty[] = $product_id;
            }
        }

        if ( empty( $dirty ) ) {
            return true;
        }

        // Enqueue in batches.
        $batch_size = max( 1, min( 100, (int) $this->opts['batch_size'] ) );
        $chunks     = array_chunk( $dirty, $batch_size );
        $enqueued   = 0;

        foreach ( $chunks as $chunk ) {
            $enqueued += $this->queue_service->enqueue_products( $chunk, 'sync', 10, $context );
        }

        // If queue is disabled or fails completely, fall back to legacy sync.
        if ( 0 === $enqueued ) {
            return $this->orchestrator->sync_changed_products( $context, $force );
        }

        return true;
    }

    public function sync_products( array $product_ids, $context = 'bulk', $force = false ) {
        // Enqueue products instead of syncing directly.
        $ids = array_values( array_unique( array_filter( $product_ids ) ) );
        
        if ( empty( $ids ) ) {
            return true;
        }

        // Enqueue in batches.
        $batch_size = max( 1, min( 100, (int) $this->opts['batch_size'] ) );
        $chunks     = array_chunk( $ids, $batch_size );
        $enqueued   = 0;

        foreach ( $chunks as $chunk ) {
            $enqueued += $this->queue_service->enqueue_products( $chunk, 'sync', 10, $context );
        }

        // If queue is disabled or fails completely, fall back to legacy sync.
        if ( 0 === $enqueued ) {
            return $this->orchestrator->sync_products( $product_ids, $context, $force );
        }

        return true;
    }

    public function build_product_item( int $product_id ) {
        return $this->payload_builder->build_product_item( $product_id );
    }
}
