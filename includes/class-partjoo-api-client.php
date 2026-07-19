<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Api_Client implements PartJoo_Api_Client_Interface {

    private $config;
    private $transport;

    public function __construct( PartJoo_Config $config, PartJoo_Transport_Interface $transport ) {
        $this->config    = $config;
        $this->transport = $transport;
    }

    /**
     * @param array $payload PartJoo API payload.
     * @return array|WP_Error
     */
    public function send( array $payload ) {
        $endpoint = esc_url_raw( $this->config->get( 'endpoint', PartJoo_Product_Sync::DEFAULT_ENDPOINT ) ?: PartJoo_Product_Sync::DEFAULT_ENDPOINT );
        $headers  = [ 'Content-Type' => 'application/json; charset=utf-8' ];
        $api_key  = $this->config->get( 'api_key', '' );

        if ( ! empty( $api_key ) ) {
            $headers['X-PartJoo-Key'] = $api_key;
        }

        $args = [
            'timeout' => 20,
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
        ];

        $response = $this->transport->post( $endpoint, $args );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 500 ) {
            usleep( 200000 );
            $response = $this->transport->post( $endpoint, $args );
        }

        return $response;
    }
}
