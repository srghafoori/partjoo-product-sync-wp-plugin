<?php
/**
 * PartJoo Queue Processor
 *
 * Processes pending queue items by building payloads and sending them to the API.
 *
 * @package PartJoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PartJoo_Queue_Processor {

	/**
	 * Queue repository.
	 *
	 * @var PartJoo_Queue_Repository_Interface
	 */
	private $repository;

	/**
	 * Payload builder.
	 *
	 * @var PartJoo_Payload_Builder
	 */
	private $payload_builder;

	/**
	 * Signature service.
	 *
	 * @var PartJoo_Signature_Service
	 */
	private $signatures;

	/**
	 * API client.
	 *
	 * @var PartJoo_Api_Client_Interface
	 */
	private $api_client;

	/**
	 * Logger.
	 *
	 * @var PartJoo_Logger
	 */
	private $logger;

	/**
	 * Product repository.
	 *
	 * @var PartJoo_Product_Repository
	 */
	private $products;

	/**
	 * Config.
	 *
	 * @var PartJoo_Config
	 */
	private $config;

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Constructor.
	 *
	 * @param PartJoo_Queue_Repository_Interface $repository      Queue repository.
	 * @param PartJoo_Payload_Builder            $payload_builder Payload builder.
	 * @param PartJoo_Signature_Service          $signatures      Signature service.
	 * @param PartJoo_Api_Client_Interface       $api_client      API client.
	 * @param PartJoo_Logger                     $logger          Logger.
	 * @param PartJoo_Product_Repository         $products        Product repository.
	 * @param PartJoo_Config                     $config          Config.
	 */
	public function __construct(
		PartJoo_Queue_Repository_Interface $repository,
		PartJoo_Payload_Builder $payload_builder,
		PartJoo_Signature_Service $signatures,
		PartJoo_Api_Client_Interface $api_client,
		PartJoo_Logger $logger,
		PartJoo_Product_Repository $products,
		PartJoo_Config $config
	) {
		$this->repository      = $repository;
		$this->payload_builder = $payload_builder;
		$this->signatures      = $signatures;
		$this->api_client      = $api_client;
		$this->logger          = $logger;
		$this->products        = $products;
		$this->config          = $config;
	}

	/**
	 * Process pending queue items.
	 *
	 * @param int $batch_size Number of items to process per batch.
	 * @return array Summary with processed and failed counts.
	 */
	public function process_queue( $batch_size = 20 ) {
		$batch_size = max( 1, min( 100, (int) $batch_size ) );

		$claimed_items = $this->claim_items( $batch_size );

		if ( empty( $claimed_items ) ) {
			return [
				'processed' => 0,
				'failed'    => 0,
			];
		}

		$processed = 0;
		$failed    = 0;

		foreach ( $claimed_items as $item ) {
			$success = $this->process_item( $item );

			if ( $success ) {
				$this->repository->mark_processed( $item->_queue_id, true );
				$processed++;
			} else {
				$this->repository->mark_failed( $item->_queue_id, $this->last_error, 0 );
				$failed++;
			}
		}

		return [
			'processed' => $processed,
			'failed'    => $failed,
		];
	}

	/**
	 * Claim pending items for processing.
	 *
	 * @param int $limit Number of items to claim.
	 * @return PartJoo_Queue_Item[] Array of claimed queue items.
	 */
	private function claim_items( $limit ) {
		global $wpdb;

		$table = $this->repository->get_table_name();
		$limit = (int) $limit;

		// Atomically claim items by updating their status to 'processing'.
		// We use a subquery to select IDs first, then update them.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = 'pending' ORDER BY priority ASC, created_at ASC LIMIT %d",
				$limit
			)
		);

		if ( empty( $ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now = current_time( 'mysql' );

		// Update status to processing atomically.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id IN ($placeholders)",
				$now,
				...$ids
			)
		);

		// Fetch the claimed items with full data.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id IN ($placeholders) ORDER BY priority ASC, created_at ASC",
				...$ids
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return [];
		}

		$items = [];
		foreach ( $results as $row ) {
			$data = ! empty( $row['data'] ) ? json_decode( $row['data'], true ) : [];
			if ( ! is_array( $data ) ) {
				$data = [];
			}

			$item = new PartJoo_Queue_Item(
				(int) $row['product_id'],
				(bool) $row['is_variation'],
				$row['action'],
				(int) $row['priority'],
				$row['context'],
				$data,
				$row['created_at']
			);

			// Store the queue ID on the item for later reference.
			$item->_queue_id = (int) $row['id'];

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Process a single queue item.
	 *
	 * @param PartJoo_Queue_Item $item Queue item.
	 * @return bool True on success, false on failure.
	 */
	private function process_item( PartJoo_Queue_Item $item ) {
		$this->last_error = '';

		$product_id = $item->get_product_id();
		$action     = $item->get_action();

		if ( 'delete' === $action ) {
			return $this->process_deletion( $item );
		}

		return $this->process_upsert( $item );
	}

	/**
	 * Process a product upsert (create/update).
	 *
	 * @param PartJoo_Queue_Item $item Queue item.
	 * @return bool True on success, false on failure.
	 */
	private function process_upsert( PartJoo_Queue_Item $item ) {
		$product_id = $item->get_product_id();

		// Verify product still exists and is published.
		if ( 'publish' !== $this->products->get_post_status( $product_id ) ) {
			$this->last_error = 'Product not published';
			return false;
		}

		$domain = trim( (string) $this->config->get( 'domain' ) );
		if ( '' === $domain ) {
			$this->last_error = 'Missing domain configuration';
			return false;
		}

		// Build the product entry.
		$entries = $this->payload_builder->build_product_entries( [ $product_id ], false );

		if ( empty( $entries ) ) {
			$this->last_error = 'Failed to build product entry';
			return false;
		}

		$payload = $this->payload_builder->build_payload_from_entries( $domain, $entries );

		// Send the payload.
		$response = $this->api_client->send( $payload );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->last_error = 'API returned status code: ' . $code;
			return false;
		}

		// Update signatures and log success.
		$entry      = reset( $entries );
		$item_data  = $this->payload_builder->build_product_item( $product_id );
		$signature  = $this->signatures->make( $item_data );
		$is_variation = $item->is_variation();
		$payload_hash = sha1( wp_json_encode( $payload ) );

		$this->logger->log_product_sync( $product_id, $is_variation, $signature, $payload_hash, $response, 'queue', 1 );
		update_post_meta( $product_id, '_partjoo_sig_sent', $signature );

		return true;
	}

	/**
	 * Process a product deletion (tombstone).
	 *
	 * @param PartJoo_Queue_Item $item Queue item.
	 * @return bool True on success, false on failure.
	 */
	private function process_deletion( PartJoo_Queue_Item $item ) {
		$product_id = $item->get_product_id();

		$domain = trim( (string) $this->config->get( 'domain' ) );
		if ( '' === $domain ) {
			$this->last_error = 'Missing domain configuration';
			return false;
		}

		// Get product (may already be deleted, so we need to handle that).
		$product = $this->products->get_product( $product_id );

		// Build deletion payload.
		$payload = $this->payload_builder->build_deletion_payload( $product, $product_id, $domain );

		// Send the payload.
		$response = $this->api_client->send( $payload );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->last_error = 'API returned status code: ' . $code;
			return false;
		}

		// Log success.
		$is_variation = $item->is_variation();
		$payload_hash = sha1( wp_json_encode( $payload ) );
		$signature    = $this->signatures->make( [] );

		$this->logger->log_product_sync( $product_id, $is_variation, $signature, $payload_hash, $response, 'queue_delete', 1 );

		return true;
	}
}
