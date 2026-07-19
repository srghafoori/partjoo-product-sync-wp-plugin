<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Container {

    const CONFIG     = 'config';
    const TRANSPORT  = 'transport';
    const API_CLIENT = 'api_client';
    const LOGGER     = 'logger';

    private static $instance = null;
    private $services = [];

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {}

    public function get( $service ) {
        if ( isset( $this->services[ $service ] ) ) {
            return $this->services[ $service ];
        }

        switch ( $service ) {
            case self::CONFIG:
                $instance = new PartJoo_Config();
                break;
            case self::TRANSPORT:
                $instance = new PartJoo_WP_Http_Transport();
                break;
            case self::API_CLIENT:
                $instance = new PartJoo_Api_Client( $this->get( self::CONFIG ), $this->get( self::TRANSPORT ) );
                break;
            case self::LOGGER:
                $instance = new PartJoo_Logger( PartJoo_State::instance() );
                break;
            default:
                throw new InvalidArgumentException( 'Unknown PartJoo service: ' . $service );
        }

        $this->services[ $service ] = $instance;

        return $instance;
    }
}
