<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Config {

    private $options = [];

    public function __construct() {
        $this->options = wp_parse_args( get_option( PartJoo_State::OPTS_KEY, [] ), self::defaults() );
    }

    public static function defaults() {
        return [
            'endpoint'           => PartJoo_Product_Sync::DEFAULT_ENDPOINT,
            'api_key'            => '',
            'domain'             => '',
            'batch_size'         => 100,
            'send_on_save'       => 1,
            'send_on_events'     => 1,
            'convert_toman_rial' => 1,
            'force_unit'         => 'rial',
            'default_condition'  => 'new',
            'send_variations'    => 0,
            'cron_recurrence'    => 'hourly',
        ];
    }

    public function all() {
        return $this->options;
    }

    public function get( $key, $default = null ) {
        return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
    }
}
