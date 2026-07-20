<?php
/**
 * PartJoo Queue Repository Implementation
 *
 * WordPress database-backed queue repository.
 *
 * @package PartJoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PartJoo_Queue_Repository implements PartJoo_Queue_Repository_Interface {

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'partjoo_queue';
	}

	/**
	 * Get the table name.
	 *
	 * @return string Table name.
	 */
	public function get_table_name() {
		return $this->table;
	}

	/**
	 * Install the queue table.
	 */
	public function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			is_variation TINYINT(1) NOT NULL DEFAULT 0,
			action VARCHAR(32) NOT NULL DEFAULT 'sync',
			priority INT NOT NULL DEFAULT 10,
			context VARCHAR(32) NOT NULL DEFAULT 'bulk',
			data LONGTEXT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			error_message TEXT NULL,
			retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
			next_retry_at DATETIME NULL,
			processed_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status_idx (status),
			KEY priority_idx (priority, created_at),
			KEY product_action_idx (product_id, action),
			KEY created_idx (created_at),
			KEY retry_idx (status, next_retry_at)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Add an item to the queue.
	 *
	 * @param PartJoo_Queue_Item_Interface $item Queue item.
	 * @return int Inserted row ID.
	 */
	public function enqueue( PartJoo_Queue_Item_Interface $item ) {
		global $wpdb;

		$data_json = ! empty( $item->get_data() ) ? wp_json_encode( $item->get_data() ) : null;

		$result = $wpdb->insert(
			$this->table,
			[
				'product_id'    => $item->get_product_id(),
				'is_variation'  => $item->is_variation() ? 1 : 0,
				'action'        => $item->get_action(),
				'priority'      => $item->get_priority(),
				'context'       => $item->get_context(),
				'data'          => $data_json,
				'status'        => 'pending',
				'retry_count'   => 0,
				'created_at'    => $item->get_created_at(),
				'updated_at'    => current_time( 'mysql' ),
			],
			[
				'%d',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
			]
		);

		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Add multiple items to the queue.
	 *
	 * @param PartJoo_Queue_Item_Interface[] $items Queue items.
	 * @return int Number of items inserted.
	 */
	public function enqueue_multiple( array $items ) {
		$count = 0;
		foreach ( $items as $item ) {
			$id = $this->enqueue( $item );
			if ( $id > 0 ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Get pending items from the queue.
	 *
	 * @param int $limit Maximum number of items to retrieve.
	 * @return PartJoo_Queue_Item_Interface[] Array of queue items.
	 */
	public function get_pending( $limit = 100 ) {
		global $wpdb;

		$limit = max( 1, min( 1000, (int) $limit ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = 'pending' ORDER BY priority ASC, created_at ASC LIMIT %d",
				$limit
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

			$items[] = new PartJoo_Queue_Item(
				(int) $row['product_id'],
				(bool) $row['is_variation'],
				$row['action'],
				(int) $row['priority'],
				$row['context'],
				$data,
				$row['created_at']
			);
		}

		return $items;
	}

	/**
	 * Mark an item as processed.
	 *
	 * @param int  $queue_id Queue item ID.
	 * @param bool $success  Whether processing was successful.
	 * @return bool True on success, false on failure.
	 */
	public function mark_processed( $queue_id, $success = true ) {
		global $wpdb;

		$result = $wpdb->update(
			$this->table,
			[
				'status'       => $success ? 'processed' : 'failed',
				'processed_at' => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			],
			[ 'id' => (int) $queue_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Mark an item as failed with retry scheduling.
	 *
	 * @param int    $queue_id    Queue item ID.
	 * @param string $error       Error message.
	 * @param int    $retry_count Number of retries attempted.
	 * @return bool True on success, false on failure.
	 */
	public function mark_failed( $queue_id, $error = '', $retry_count = 0 ) {
		global $wpdb;

		$result = $wpdb->update(
			$this->table,
			[
				'status'        => 'failed',
				'error_message' => sanitize_text_field( $error ),
				'retry_count'   => (int) $retry_count,
				'processed_at'  => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'id' => (int) $queue_id ],
			[ '%s', '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Schedule a retry for a failed item.
	 *
	 * @param int $queue_id      Queue item ID.
	 * @param int $retry_count   New retry count.
	 * @param int $delay_seconds Delay in seconds before next retry.
	 * @return bool True on success, false on failure.
	 */
	public function schedule_retry( $queue_id, $retry_count, $delay_seconds ) {
		global $wpdb;

		$next_retry = gmdate( 'Y-m-d H:i:s', time() + (int) $delay_seconds );

		$result = $wpdb->update(
			$this->table,
			[
				'status'       => 'pending',
				'retry_count'  => (int) $retry_count,
				'error_message'=> null,
				'updated_at'   => current_time( 'mysql' ),
				'next_retry_at'=> $next_retry,
			],
			[ 'id' => (int) $queue_id ],
			[ '%s', '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Get items ready for retry.
	 *
	 * @param int $limit Maximum number of items to retrieve.
	 * @return PartJoo_Queue_Item_Interface[] Array of queue items.
	 */
	public function get_due_for_retry( $limit = 100 ) {
		global $wpdb;

		$limit = max( 1, min( 1000, (int) $limit ) );
		$now = current_time( 'mysql' );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = 'failed' AND next_retry_at IS NOT NULL AND next_retry_at <= %s ORDER BY priority ASC, created_at ASC LIMIT %d",
				$now,
				$limit
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

			$items[] = new PartJoo_Queue_Item(
				(int) $row['product_id'],
				(bool) $row['is_variation'],
				$row['action'],
				(int) $row['priority'],
				$row['context'],
				$data,
				$row['created_at']
			);
		}

		return $items;
	}

	/**
	 * Remove an item from the queue.
	 *
	 * @param int $queue_id Queue item ID.
	 * @return bool True on success, false on failure.
	 */
	public function remove( $queue_id ) {
		global $wpdb;

		$result = $wpdb->delete(
			$this->table,
			[ 'id' => (int) $queue_id ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Clear old processed items.
	 *
	 * @param int $older_than_days Days threshold.
	 * @return int Number of rows removed.
	 */
	public function clear_old( $older_than_days = 7 ) {
		global $wpdb;

		$days = max( 1, (int) $older_than_days );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE status IN ('processed', 'failed') AND processed_at < %s",
				$cutoff
			)
		);

		return (int) $result;
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array Statistics array with pending, failed, total counts.
	 */
	public function get_stats() {
		global $wpdb;

		$results = $wpdb->get_row(
			"SELECT 
				COUNT(*) as total,
				SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
				SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
			FROM {$this->table}",
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return [
				'total'     => 0,
				'pending'   => 0,
				'processed' => 0,
				'failed'    => 0,
			];
		}

		return [
			'total'     => (int) $results['total'],
			'pending'   => (int) $results['pending'],
			'processed' => (int) $results['processed'],
			'failed'    => (int) $results['failed'],
		];
	}

	/**
	 * Check if a product is already in the pending queue.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $action     Action type.
	 * @return bool True if already queued, false otherwise.
	 */
	public function is_queued( $product_id, $action = 'sync' ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE product_id = %d AND action = %s AND status = 'pending' LIMIT 1",
				$product_id,
				$action
			)
		);

		return (bool) $result;
	}
}
