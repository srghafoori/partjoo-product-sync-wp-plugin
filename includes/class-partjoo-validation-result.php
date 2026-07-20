<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Validation_Result {

    private $errors = [];
    private $valid_entries = [];

    public function add_error( $product_id, $message ) {
        if ( ! isset( $this->errors[ $product_id ] ) ) {
            $this->errors[ $product_id ] = [];
        }

        $this->errors[ $product_id ][] = $message;
    }

    public function add_valid_entry( array $entry ) {
        $this->valid_entries[] = $entry;
    }

    public function has_errors() {
        return ! empty( $this->errors );
    }

    public function has_valid_entries() {
        return ! empty( $this->valid_entries );
    }

    public function get_errors() {
        return $this->errors;
    }

    public function get_valid_entries() {
        return $this->valid_entries;
    }
}
