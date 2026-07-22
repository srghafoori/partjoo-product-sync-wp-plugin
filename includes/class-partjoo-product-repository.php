<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Product_Repository {

    public function get_product( $product_id ) {
        return wc_get_product( $product_id );
    }

    public function get_post_status( $product_id ) {
        return get_post_status( $product_id );
    }

    public function get_post_type( $product_id ) {
        return get_post_type( $product_id );
    }

    public function get_meta( $product_id, $key ) {
        return get_post_meta( $product_id, $key, true );
    }

    public function update_meta( $product_id, $key, $value ) {
        return update_post_meta( $product_id, $key, $value );
    }

    public function get_syncable_product_ids( $include_variations ) {
        $ids = get_posts( [
            'post_type'      => [ 'product' ],
            'post_status'    => [ 'publish' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        if ( ! is_array( $ids ) ) {
            $ids = [];
        }

        if ( $include_variations ) {
            $var_ids = get_posts( [
                'post_type'      => [ 'product_variation' ],
                'post_status'    => [ 'publish' ],
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ] );

            if ( is_array( $var_ids ) ) {
                $ids = array_merge( $ids, $var_ids );
            }
        }

        return $ids;
    }

    public function is_dirty( $product_id ) {
        $signature_current = $this->get_meta( $product_id, '_partjoo_sig_current' );
        $signature_sent    = $this->get_meta( $product_id, '_partjoo_sig_sent' );

        return ! $signature_current || $signature_current !== $signature_sent;
    }
    
    public function count_dirty_products() {
        $q = new WP_Query([
            'post_type'      => ['product','product_variation'],
            'post_status'    => ['publish'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        $dirty = 0;
        foreach ( $q->posts as $pid ) {
            $sig_current = get_post_meta($pid, '_partjoo_sig_current', true);
            $sig_sent    = get_post_meta($pid, '_partjoo_sig_sent', true);
            if ( ! $sig_current || $sig_current !== $sig_sent ) $dirty++;
        }
        return $dirty;
    }
    
    public function update_signature_sent( $product_id, $signature ) {
        $this->update_meta( $product_id, '_partjoo_sig_sent', $signature );
    }
    
    public function update_signature_current( $product_id, $signature ) {
        $this->update_meta( $product_id, '_partjoo_sig_current', $signature );
    }
}