<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Signature_Service {

    public function make( $item = null ) {
        if ( empty( $item ) ) {
            return '';
        }

        $normalized = wp_json_encode( $item, JSON_UNESCAPED_UNICODE );

        return sha1( $normalized ?: '' );
    }
}
