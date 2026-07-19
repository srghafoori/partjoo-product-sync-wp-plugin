<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface PartJoo_Transport_Interface {
    /**
     * Send a POST request through the WordPress HTTP API.
     *
     * @param string $url  Request URL.
     * @param array  $args Request arguments.
     * @return array|WP_Error
     */
    public function post( $url, array $args );
}
