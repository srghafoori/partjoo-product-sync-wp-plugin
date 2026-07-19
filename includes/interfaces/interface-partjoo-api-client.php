<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface PartJoo_Api_Client_Interface {
    /**
     * Send a PartJoo API payload.
     *
     * @param array $payload PartJoo API payload.
     * @return array|WP_Error
     */
    public function send( array $payload );
}
