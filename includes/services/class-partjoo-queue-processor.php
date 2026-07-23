<?php

/**
 * PartJoo Queue Processor
 *
 * Processes pending queue items by building payloads and sending them to the API.
 *
 * @package PartJoo
 */

if (! defined('ABSPATH')) {
    exit;
}

class PartJoo_Queue_Processor
{

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
     * Maximum retry attempts.
     *
     * @var int
     */
    const MAX_RETRIES = 5;

    /**
     * Base delay for exponential backoff (in seconds).
     *
     * @var int
     */
    const BASE_DELAY = 60;

    /**
     * Maximum delay between retries (in seconds).
     *
     * @var int
     */
    const MAX_DELAY = 3600;

    /**
     * Lock transient key prefix.
     *
     * @var string
     */
    const LOCK_PREFIX = 'partjoo_queue_processing_lock';

    /**
     * Lock timeout in seconds.
     *
     * @var int
     */
    const LOCK_TIMEOUT = 300;

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
    public function process_queue($batch_size = 20)
    {
        // Prevent concurrent processing using a lock.
        if (! $this->acquire_lock()) {
            return [
                'processed' => 0,
                'failed'    => 0,
            ];
        }

        try {
            $batch_size = max(1, min(100, (int) $batch_size));

            // First, process items due for retry.
            $retry_items = $this->repository->get_due_for_retry($batch_size);
            $retry_count = 0;
            $retry_failed = 0;

            foreach ($retry_items as $item) {
                $success = $this->process_item($item);
                if ($success) {
                    $this->repository->mark_completed($item->get_queue_id());
                    $retry_count++;
                } else {
                    $new_retry = $item->get_retry_count() + 1;
                    $this->handle_failure($item->get_queue_id(), $new_retry);
                    $retry_failed++;
                }
            }

            // Calculate remaining capacity after processing retries
            $remaining_capacity = max(0, $batch_size - count($retry_items));

            // Then, process new pending items only if there's capacity left
            $claimed_items = [];
            if ($remaining_capacity > 0) {
                $claimed_items = $this->repository->claim_pending($remaining_capacity);
            }

            $processed = 0;
            $failed    = 0;

            foreach ($claimed_items as $item) {
                $success = $this->process_item($item);

                if ($success) {
                    $this->repository->mark_completed($item->get_queue_id());
                    $processed++;
                } else {
                    $this->handle_failure($item->get_queue_id(), 1);
                    $failed++;
                }
            }

            return [
                'processed' => $retry_count + $processed,
                'failed'    => $retry_failed + $failed,
            ];
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Handle failure with retry logic.
     *
     * @param int $queue_id    Queue item ID.
     * @param int $retry_count Current retry count.
     */
    private function handle_failure($queue_id, $retry_count)
    {
        if ($retry_count >= self::MAX_RETRIES) {
            // Max retries exceeded, mark as permanently failed.
            $this->repository->mark_failed($queue_id, $this->last_error . ' (max retries exceeded)', $retry_count);
        } else {
            // Schedule retry with exponential backoff.
            $delay = $this->calculate_backoff_delay($retry_count);
            $this->repository->schedule_retry($queue_id, $retry_count, $delay);
        }
    }

    /**
     * Calculate exponential backoff delay.
     *
     * @param int $retry_count Current retry count.
     * @return int Delay in seconds.
     */
    private function calculate_backoff_delay($retry_count)
    {
        $delay = self::BASE_DELAY * pow(2, $retry_count - 1);
        return min($delay, self::MAX_DELAY);
    }

    /**
     * Acquire processing lock.
     *
     * @return bool True if lock acquired, false otherwise.
     */
    private function acquire_lock()
    {
        $lock_key = self::LOCK_PREFIX . '_' . get_current_blog_id();
        $value    = get_transient($lock_key);

        if (false !== $value) {
            return false;
        }

        return set_transient($lock_key, time(), self::LOCK_TIMEOUT);
    }

    /**
     * Release processing lock.
     */
    private function release_lock()
    {
        $lock_key = self::LOCK_PREFIX . '_' . get_current_blog_id();
        delete_transient($lock_key);
    }

    /**
     * Process a single queue item.
     *
     * @param PartJoo_Queue_Item_Interface $item Queue item.
     * @return bool True on success, false on failure.
     */
    private function process_item(PartJoo_Queue_Item_Interface $item)
    {
        $this->last_error = '';

        $product_id = $item->get_product_id();
        $action     = $item->get_action();

        if ('delete' === $action) {
            return $this->process_deletion($item);
        }

        return $this->process_upsert($item);
    }

    /**
     * Process a product upsert (create/update).
     *
     * @param PartJoo_Queue_Item_Interface $item Queue item.
     * @return bool True on success, false on failure.
     */
    private function process_upsert(PartJoo_Queue_Item_Interface $item)
    {
        $product_id = $item->get_product_id();

        // Verify product still exists and is published.
        if ('publish' !== $this->products->get_post_status($product_id)) {
            $this->last_error = 'Product not published';
            return false;
        }

        $domain = trim((string) $this->config->get('domain'));
        if ('' === $domain) {
            $this->last_error = 'Missing domain configuration';
            return false;
        }

        // Build the product entry.
        $entries = $this->payload_builder->build_product_entries([$product_id], true); // Force processing for queued items

        if (empty($entries)) {
            $this->last_error = 'Failed to build product entry';
            return false;
        }

        $payload = $this->payload_builder->build_payload_from_entries($domain, $entries);

        // Send the payload.
        $response = $this->api_client->send($payload);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $this->last_error = 'API returned status code: ' . $code;
            return false;
        }

        // Update signatures and log success.
        $item_data  = $this->payload_builder->build_product_item($product_id);
        $signature  = $this->signatures->make($item_data);
        $is_variation = $item->is_variation();
        $payload_hash = sha1(wp_json_encode($payload));

        $this->logger->log_product_sync($product_id, $is_variation, $signature, $payload_hash, $response, 'queue', 1);
        $this->products->update_signature_sent($product_id, $signature);

        return true;
    }

    /**
     * Process a product deletion (tombstone).
     *
     * @param PartJoo_Queue_Item_Interface $item Queue item.
     * @return bool True on success, false on failure.
     */
    private function process_deletion(PartJoo_Queue_Item_Interface $item)
    {
        $product_id = $item->get_product_id();

        $domain = trim((string) $this->config->get('domain'));
        if ('' === $domain) {
            $this->last_error = 'Missing domain configuration';
            return false;
        }

        // Get product (may already be deleted, so we need to handle that).
        $product = $this->products->get_product($product_id);

        // Build deletion payload.
        $payload = $this->payload_builder->build_deletion_payload($product, $product_id, $domain);

        // Send the payload.
        $response = $this->api_client->send($payload);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $this->last_error = 'API returned status code: ' . $code;
            return false;
        }

        // Log success.
        $is_variation = $item->is_variation();
        $payload_hash = sha1(wp_json_encode($payload));

        // Generate signature based on the actual product data being deleted
        $product_data = $product ? $this->payload_builder->build_product_item($product_id) : [];
        $signature    = $this->signatures->make($product_data);

        $this->logger->log_product_sync($product_id, $is_variation, $signature, $payload_hash, $response, 'queue_delete', 1);

        return true;
    }
}
