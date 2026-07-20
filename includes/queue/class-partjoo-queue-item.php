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
	 * Constructor.
	 *
	 * @param int    $product_id   Product ID.
	 * @param bool   $is_variation Whether this is a variation.
	 * @param string $action       Action type.
	 * @param int    $priority     Priority value.
	 * @param string $context      Context identifier.
	 * @param array  $data         Additional data.
	 * @param string $created_at   Created timestamp.
	 */
	public function __construct(
		$product_id,
		$is_variation = false,
		$action = 'sync',
		$priority = 10,
		$context = 'bulk',
		$data = [],
		$created_at = null
	) {
		$this->product_id   = (int) $product_id;
		$this->is_variation = (bool) $is_variation;
		$this->action       = sanitize_text_field( $action );
		$this->priority     = (int) $priority;
		$this->context      = sanitize_text_field( $context );
		$this->data         = (array) $data;
		$this->created_at   = $created_at ?? current_time( 'mysql' );
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
}
