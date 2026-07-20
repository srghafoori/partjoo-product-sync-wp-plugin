<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Container {

    const CONFIG     = 'config';
    const TRANSPORT  = 'transport';
    const API_CLIENT = 'api_client';
    const LOGGER     = 'logger';
    const PRODUCTS   = 'products';
    const SIGNATURES = 'signatures';
    const PAYLOADS   = 'payloads';
    const SYNC       = 'sync';
    const PRODUCT_VALIDATOR = 'product_validator';
    const PAYLOAD_VALIDATOR = 'payload_validator';
    const QUEUE_REPO        = 'queue_repository';
    const QUEUE_SERVICE     = 'queue_service';

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
            case self::PRODUCTS:
                $instance = new PartJoo_Product_Repository();
                break;
            case self::SIGNATURES:
                $instance = new PartJoo_Signature_Service();
                break;
            case self::PAYLOADS:
                $instance = new PartJoo_Payload_Builder( $this->get( self::CONFIG ), $this->get( self::PRODUCTS ), $this->get( self::SIGNATURES ) );
                break;
            case self::SYNC:
                $instance = new PartJoo_Sync_Orchestrator( $this->get( self::CONFIG ), $this->get( self::PRODUCTS ), $this->get( self::PAYLOADS ), $this->get( self::SIGNATURES ), $this->get( self::PAYLOAD_VALIDATOR ), $this->get( self::API_CLIENT ), $this->get( self::LOGGER ), PartJoo_State::instance() );
                break;
            case self::PRODUCT_VALIDATOR:
                $instance = new PartJoo_Product_Validator();
                break;
            case self::PAYLOAD_VALIDATOR:
                $instance = new PartJoo_Payload_Validator( $this->get( self::PRODUCT_VALIDATOR ) );
                break;
            case self::QUEUE_REPO:
                $instance = new PartJoo_Queue_Repository();
                break;
            case self::QUEUE_SERVICE:
                $instance = new PartJoo_Queue_Service( $this->get( self::QUEUE_REPO ) );
                break;
            default:
                throw new InvalidArgumentException( 'Unknown PartJoo service: ' . $service );
        }

        $this->services[ $service ] = $instance;

        return $instance;
    }
}
