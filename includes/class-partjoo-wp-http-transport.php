<?php
if ( ! defined( 'ABSPATH' ) {
    exit;
}

class PartJoo_WP_Http_Transport implements PartJoo_Transport_Interface {

    /**
     * @param string $url  Request URL.
     * @param array  $args Request arguments.
     * @return array|WP_Error
     */
    public function post( $url, array $args ) {
        return wp_remote_post( $url, $args );
    }
}
