<?php
/**
 * PartJoo Queue Repository Interface
 *
 * Interface for queue storage operations.
 *
 * @package PartJoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PartJoo_Queue_Repository_Interface {
	/**
	 * Add an item to the queue.
	 *
	 * @param PartJoo_Queue_Item_Interface $item Queue item.
	 * @return int Inserted row ID.
	 */
	public function enqueue( PartJoo_Queue_Item_Interface $item );

	/**
	 * Add multiple items to the queue.
	 *
	 * @param PartJoo_Queue_Item_Interface[] $items Queue items.
	 * @return int Number of items inserted.
	 */
	public function enqueue_multiple( array $items );

	/**
	 * Claim pending items atomically for processing.
	 *
	 * @param int $limit Maximum number of items to claim.
	 * @return PartJoo_Queue_Item_Interface[] Array of claimed queue items with status set to 'processing'.
	 */
	public function claim_pending( $limit = 100 );

	/**
	 * Get items due for retry.
	 *
	 * @param int $limit Maximum number of items to retrieve.
	 * @return PartJoo_Queue_Item_Interface[] Array of queue items.
	 */
	public function get_due_for_retry( $limit = 100 );

	/**
	 * Mark an item as completed.
	 *
	 * @param int $queue_id Queue item ID.
	 * @return bool True on success, false on failure.
	 */
	public function mark_completed( $queue_id );

	/**
	 * Mark an item as failed.
	 *
	 * @param int    $queue_id    Queue item ID.
	 * @param string $error       Error message.
	 * @param int    $retry_count Number of retries attempted.
	 * @return bool True on success, false on failure.
	 */
	public function mark_failed( $queue_id, $error = '', $retry_count = 0 );

	/**
	 * Schedule a retry for a failed item.
	 *
	 * @param int $queue_id      Queue item ID.
	 * @param int $retry_count   New retry count.
	 * @param int $delay_seconds Delay in seconds before next retry.
	 * @return bool True on success, false on failure.
	 */
	public function schedule_retry( $queue_id, $retry_count, $delay_seconds );

	/**
	 * Remove an item from the queue.
	 *
	 * @param int $queue_id Queue item ID.
	 * @return bool True on success, false on failure.
	 */
	public function remove( $queue_id );

	/**
	 * Clear old processed/failed items.
	 *
	 * @param int $older_than_days Days threshold.
	 * @return int Number of rows removed.
	 */
	public function clear_old( $older_than_days = 7 );

	/**
	 * Get queue statistics.
	 *
	 * @return array Statistics array with pending, failed, total counts.
	 */
	public function get_stats();

	/**
	 * Check if a product is already in the pending queue.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $action     Action type.
	 * @return bool True if already queued, false otherwise.
	 */
	public function is_queued( $product_id, $action = 'sync' );
}
