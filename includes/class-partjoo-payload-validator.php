<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PartJoo_Payload_Validator {

    private $product_validator;

    public function __construct( PartJoo_Product_Validator $product_validator ) {
        $this->product_validator = $product_validator;
    }

    public function validate( array $payload, array $entries ) {
        $result = new PartJoo_Validation_Result();
        $errors = $this->validate_envelope( $payload );

        foreach ( $entries as $entry ) {
            $product_id     = $entry['product_id'];
            $product_result = $this->product_validator->validate( $product_id, $entry['item'] );

            foreach ( $errors as $error ) {
                $product_result->add_error( $product_id, $error );
            }

            foreach ( $product_result->get_errors() as $messages ) {
                foreach ( $messages as $message ) {
                    $result->add_error( $product_id, $message );
                }
            }

            if ( ! $product_result->has_errors() ) {
                $result->add_valid_entry( $entry );
            }
        }

        return $result;
    }

    private function validate_envelope( array $payload ) {
        $errors = [];

        if ( ! isset( $payload['route'] ) || 'crawler/addProductsToPartjoo' !== $payload['route'] ) {
            $errors[] = 'Payload route must be "crawler/addProductsToPartjoo".';
        }

        if ( ! isset( $payload['content'] ) || ! is_array( $payload['content'] ) ) {
            return array_merge( $errors, [ 'Payload content must be an object.' ] );
        }

        $content = $payload['content'];
        if ( ! isset( $content['domain'] ) || ! is_string( $content['domain'] ) || '' === trim( $content['domain'] ) ) {
            $errors[] = 'Payload content.domain must be a non-empty string.';
        }
        if ( ! isset( $content['allLinks'] ) || ! is_array( $content['allLinks'] ) || ! empty( $content['allLinks'] ) ) {
            $errors[] = 'Payload content.allLinks must be an empty array.';
        }
        if ( ! isset( $content['products'] ) || ! is_array( $content['products'] ) || empty( $content['products'] ) ) {
            $errors[] = 'Payload content.products must contain at least one product.';
        } elseif ( count( $content['products'] ) > 100 ) {
            $errors[] = 'Payload content.products cannot contain more than 100 products.';
        }

        return $errors;
    }
}
