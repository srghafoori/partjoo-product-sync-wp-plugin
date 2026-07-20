<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Product_Validator {

    private $conditions = [ 'new', 'oem', 'copy', 'renew', 'used', 'nos' ];
    private $units      = [ 'toman', 'rial', 'dollar', 'yuan' ];

    public function validate( $product_id, $product ) {
        $result = new PartJoo_Validation_Result();

        if ( ! is_array( $product ) ) {
            $result->add_error( $product_id, 'Product payload could not be built.' );

            return $result;
        }

        $this->validate_title( $result, $product_id, $product );
        $this->validate_url( $result, $product_id, $product, 'url', true );
        $this->validate_availability( $result, $product_id, $product );
        $this->validate_price( $result, $product_id, $product );
        $this->validate_stock( $result, $product_id, $product );
        $this->validate_condition( $result, $product_id, $product );
        $this->validate_bulk_prices( $result, $product_id, $product );
        $this->validate_optional_strings( $result, $product_id, $product );

        return $result;
    }

    private function validate_title( PartJoo_Validation_Result $result, $product_id, array $product ) {
        if ( ! isset( $product['title'] ) || ! is_string( $product['title'] ) || '' === trim( $product['title'] ) ) {
            $result->add_error( $product_id, 'Required field "title" must be a non-empty string.' );
        }
    }

    private function validate_url( PartJoo_Validation_Result $result, $product_id, array $product, $field, $required = false ) {
        if ( ! array_key_exists( $field, $product ) ) {
            if ( $required ) {
                $result->add_error( $product_id, 'Required field "' . $field . '" is missing.' );
            }

            return;
        }

        if ( ! is_string( $product[ $field ] ) || '' === trim( $product[ $field ] ) ) {
            $result->add_error( $product_id, 'Field "' . $field . '" must be a non-empty absolute URL.' );

            return;
        }

        $parts = wp_parse_url( $product[ $field ] );
        if ( false === $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
            $result->add_error( $product_id, 'Field "' . $field . '" must be an absolute HTTP or HTTPS URL.' );
        }
    }

    private function validate_availability( PartJoo_Validation_Result $result, $product_id, array $product ) {
        if ( ! isset( $product['availability'] ) || ! is_string( $product['availability'] ) || ! in_array( $product['availability'], [ '1', '-1' ], true ) ) {
            $result->add_error( $product_id, 'Required field "availability" must be the string "1" or "-1".' );
        }
    }

    private function validate_price( PartJoo_Validation_Result $result, $product_id, array $product ) {
        if ( ! array_key_exists( 'price', $product ) ) {
            return;
        }

        if ( ! $this->is_number( $product['price'] ) || $product['price'] < 0 ) {
            $result->add_error( $product_id, 'Field "price" must be a non-negative JSON number.' );
        }

        $unit = isset( $product['unit'] ) ? $product['unit'] : 'rial';
        if ( 'rial' !== $unit ) {
            $result->add_error( $product_id, 'Field "price" must be sent in rial; set "unit" to "rial" or omit it.' );
        }
    }

    private function validate_stock( PartJoo_Validation_Result $result, $product_id, array $product ) {
        if ( ! array_key_exists( 'stock', $product ) ) {
            $result->add_error( $product_id, 'Field "stock" must be 0 when an exact stock quantity is unavailable.' );

            return;
        }

        if ( ! $this->is_number( $product['stock'] ) || $product['stock'] < 0 || (int) $product['stock'] !== $product['stock'] ) {
            $result->add_error( $product_id, 'Field "stock" must be a non-negative whole number.' );
        }
    }

    private function validate_condition( PartJoo_Validation_Result $result, $product_id, array $product ) {
        if ( array_key_exists( 'condition', $product ) && ( ! is_string( $product['condition'] ) || ! in_array( $product['condition'], $this->conditions, true ) ) ) {
            $result->add_error( $product_id, 'Field "condition" must be one of: ' . implode( ', ', $this->conditions ) . '.' );
        }
    }

    private function validate_bulk_prices( PartJoo_Validation_Result $result, $product_id, array $product ) {
        if ( ! array_key_exists( 'bulkPrices', $product ) ) {
            return;
        }

        if ( ! is_array( $product['bulkPrices'] ) || empty( $product['bulkPrices'] ) ) {
            $result->add_error( $product_id, 'Field "bulkPrices" must be a non-empty array.' );

            return;
        }

        foreach ( $product['bulkPrices'] as $index => $bulk_price ) {
            if ( ! is_array( $bulk_price ) || ! isset( $bulk_price['quantity'] ) || ! is_int( $bulk_price['quantity'] ) || $bulk_price['quantity'] <= 0 ) {
                $result->add_error( $product_id, 'bulkPrices[' . $index . '].quantity must be a positive whole number.' );
            }

            if ( ! is_array( $bulk_price ) || ! isset( $bulk_price['price'] ) || ! $this->is_number( $bulk_price['price'] ) || $bulk_price['price'] < 0 ) {
                $result->add_error( $product_id, 'bulkPrices[' . $index . '].price must be a non-negative JSON number in rial.' );
            }
        }
    }

    private function validate_optional_strings( PartJoo_Validation_Result $result, $product_id, array $product ) {
        if ( array_key_exists( 'unit', $product ) && ( ! is_string( $product['unit'] ) || ! in_array( $product['unit'], $this->units, true ) ) ) {
            $result->add_error( $product_id, 'Field "unit" must be one of: ' . implode( ', ', $this->units ) . '.' );
        }

        $this->validate_url( $result, $product_id, $product, 'image' );

        foreach ( [ 'partName', 'partNumber', 'description' ] as $field ) {
            if ( array_key_exists( $field, $product ) && ! is_string( $product[ $field ] ) ) {
                $result->add_error( $product_id, 'Field "' . $field . '" must be a string.' );
            }
        }
    }

    private function is_number( $value ) {
        return is_int( $value ) || is_float( $value );
    }
}
