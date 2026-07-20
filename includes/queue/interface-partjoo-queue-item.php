<?php
/**
 * PartJoo Queue Item Interface
 *
 * Represents a single item in the sync queue.
 *
 * @package PartJoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PartJoo_Queue_Item_Interface {
	/**
	 * Get the product ID.
	 *
	 * @return int Product ID.
	 */
	public function get_product_id();

	/**
	 * Get whether this is a variation.
	 *
	 * @return bool True if variation, false otherwise.
	 */
	public function is_variation();

	/**
	 * Get the action type (sync, delete, etc).
	 *
	 * @return string Action type.
	 */
	public function get_action();

	/**
	 * Get the priority (lower = higher priority).
	 *
	 * @return int Priority value.
	 */
	public function get_priority();

	/**
	 * Get the context (single, bulk, cron, event, etc).
	 *
	 * @return string Context identifier.
	 */
	public function get_context();

	/**
	 * Get additional data for the queue item.
	 *
	 * @return array Additional data.
	 */
	public function get_data();

	/**
	 * Get the created timestamp.
	 *
	 * @return string MySQL datetime string.
	 */
	public function get_created_at();
}
