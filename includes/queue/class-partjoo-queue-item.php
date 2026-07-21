<?php
/**
 * PartJoo Queue Item Implementation
 *
 * Default implementation of a queue item.
 *
 * @package PartJoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PartJoo_Queue_Item implements PartJoo_Queue_Item_Interface {

	/**
	 * Queue ID.
	 *
	 * @var int
	 */
	private $queue_id;

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Whether this is a variation.
	 *
	 * @var bool
	 */
	private $is_variation;

	/**
	 * Action type (sync, delete).
	 *
	 * @var string
	 */
	private $action;

	/**
	 * Priority (lower = higher priority).
	 *
	 * @var int
	 */
	private $priority;

	/**
	 * Context identifier.
	 *
	 * @var string
	 */
	private $context;

	/**
	 * Additional data.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Created timestamp.
	 *
	 * @var string
	 */
	private $created_at;

	/**
	 * Retry count.
	 *
	 * @var int
	 */
	private $retry_count;

	/**
	 * Next retry timestamp.
	 *
	 * @var string|null
	 */
	private $next_retry_at;

	/**
	 * Constructor.
	 *
	 * @param int       $product_id    Product ID.
	 * @param bool      $is_variation  Whether this is a variation.
	 * @param string    $action        Action type.
	 * @param int       $priority      Priority value.
	 * @param string    $context       Context identifier.
	 * @param array     $data          Additional data.
	 * @param string    $created_at    Created timestamp.
	 * @param int       $queue_id      Queue ID (default 0 for new items).
	 * @param int       $retry_count   Retry count (default 0).
	 * @param string|null $next_retry_at Next retry timestamp (default null).
	 */
	public function __construct(
		$product_id,
		$is_variation = false,
		$action = 'sync',
		$priority = 10,
		$context = 'bulk',
		$data = [],
		$created_at = null,
		$queue_id = 0,
		$retry_count = 0,
		$next_retry_at = null
	) {
		$this->product_id    = (int) $product_id;
		$this->is_variation  = (bool) $is_variation;
		$this->action        = sanitize_text_field( $action );
		$this->priority      = (int) $priority;
		$this->context       = sanitize_text_field( $context );
		$this->data          = (array) $data;
		$this->created_at    = $created_at ?? current_time( 'mysql' );
		$this->queue_id      = (int) $queue_id;
		$this->retry_count   = (int) $retry_count;
		$this->next_retry_at = $next_retry_at;
	}

	public function get_queue_id() {
		return $this->queue_id;
	}

	public function get_product_id() {
		return $this->product_id;
	}

	public function is_variation() {
		return $this->is_variation;
	}

	public function get_action() {
		return $this->action;
	}

	public function get_priority() {
		return $this->priority;
	}

	public function get_context() {
		return $this->context;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_created_at() {
		return $this->created_at;
	}

	public function get_retry_count() {
		return $this->retry_count;
	}

	public function get_next_retry_at() {
		return $this->next_retry_at;
	}
}
