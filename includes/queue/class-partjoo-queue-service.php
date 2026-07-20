<?php
/**
 * PartJoo Queue Service
 *
 * Service layer for queue operations.
 * Provides abstraction over the queue repository.
 *
 * @package PartJoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PartJoo_Queue_Service {

	/**
	 * Queue repository.
	 *
	 * @var PartJoo_Queue_Repository_Interface
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param PartJoo_Queue_Repository_Interface $repository Queue repository.
	 */
	public function __construct( PartJoo_Queue_Repository_Interface $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Add a product to the sync queue.
	 *
	 * @param int    $product_id   Product ID.
	 * @param bool   $is_variation Whether this is a variation.
	 * @param string $action       Action type (sync, delete).
	 * @param int    $priority     Priority (lower = higher priority).
	 * @param string $context      Context identifier.
	 * @param array  $data         Additional data.
	 * @return int Queue item ID, or 0 on failure.
	 */
	public function enqueue_product(
		$product_id,
		$is_variation = false,
		$action = 'sync',
		$priority = 10,
		$context = 'bulk',
		$data = []
	) {
		// Prevent duplicate entries for same product/action combination.
		if ( $this->repository->is_queued( $product_id, $action ) ) {
			return 0;
		}

		$item = new PartJoo_Queue_Item(
			$product_id,
			$is_variation,
			$action,
			$priority,
			$context,
			$data
		);

		return $this->repository->enqueue( $item );
	}

	/**
	 * Add multiple products to the queue.
	 *
	 * @param array  $product_ids  Array of product IDs.
	 * @param string $action       Action type.
	 * @param int    $priority     Priority.
	 * @param string $context      Context identifier.
	 * @return int Number of items enqueued.
	 */
	public function enqueue_products(
		array $product_ids,
		$action = 'sync',
		$priority = 10,
		$context = 'bulk'
	) {
		$items = [];
		foreach ( $product_ids as $product_id ) {
			// Skip if already queued.
			if ( $this->repository->is_queued( $product_id, $action ) ) {
				continue;
			}

			$items[] = new PartJoo_Queue_Item(
				(int) $product_id,
				false,
				$action,
				$priority,
				$context,
				[]
			);
		}

		return $this->repository->enqueue_multiple( $items );
	}

	/**
	 * Get pending queue items.
	 *
	 * @param int $limit Maximum number of items.
	 * @return PartJoo_Queue_Item_Interface[] Array of queue items.
	 */
	public function get_pending( $limit = 100 ) {
		return $this->repository->get_pending( $limit );
	}

	/**
	 * Mark a queue item as processed.
	 *
	 * @param int  $queue_id Queue item ID.
	 * @param bool $success  Whether processing succeeded.
	 * @return bool True on success.
	 */
	public function mark_processed( $queue_id, $success = true ) {
		return $this->repository->mark_processed( $queue_id, $success );
	}

	/**
	 * Mark a queue item as failed.
	 *
	 * @param int    $queue_id    Queue item ID.
	 * @param string $error       Error message.
	 * @param int    $retry_count Retry count.
	 * @return bool True on success.
	 */
	public function mark_failed( $queue_id, $error = '', $retry_count = 0 ) {
		return $this->repository->mark_failed( $queue_id, $error, $retry_count );
	}

	/**
	 * Remove a queue item.
	 *
	 * @param int $queue_id Queue item ID.
	 * @return bool True on success.
	 */
	public function remove( $queue_id ) {
		return $this->repository->remove( $queue_id );
	}

	/**
	 * Clear old processed items.
	 *
	 * @param int $older_than_days Days threshold.
	 * @return int Number of items cleared.
	 */
	public function clear_old( $older_than_days = 7 ) {
		return $this->repository->clear_old( $older_than_days );
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array Statistics array.
	 */
	public function get_stats() {
		return $this->repository->get_stats();
	}

	/**
	 * Check if a product is in the queue.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $action     Action type.
	 * @return bool True if queued.
	 */
	public function is_queued( $product_id, $action = 'sync' ) {
		return $this->repository->is_queued( $product_id, $action );
	}
}
