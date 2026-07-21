<?php
if ( ! defined('ABSPATH') ) { exit; }

class PartJoo_CLI {
    /**
     * wp partjoo sync [--force] [--ids=1,2,3] [--process-now]
     */
    public function sync( $args, $assoc_args ) {
        $force       = ! empty( $assoc_args['force'] );
        $process_now = ! empty( $assoc_args['process-now'] );
        $ids         = [];
        
        if ( ! empty($assoc_args['ids']) ) {
            $ids = array_map('absint', explode(',', (string)$assoc_args['ids']));
        }

        // Enqueue products instead of syncing directly.
        if ( ! empty($ids) ) {
            PartJoo_Product_Sync::instance()->sync_products($ids, 'cli', $force);
        } else {
            PartJoo_Product_Sync::instance()->sync_changed_products('cli', $force);
        }

        // If --process-now flag is set, immediately process the queue.
        if ( $process_now ) {
            $container = PartJoo_Container::instance();
            $processor = $container->get( PartJoo_Container::QUEUE_PROCESSOR );
            $result    = $processor->process_queue( 50 );

            WP_CLI::line( sprintf( 'Processed: %d, Failed: %d', $result['processed'], $result['failed'] ) );
            
            if ( $result['failed'] > 0 ) {
                WP_CLI::warning( 'Some jobs failed. Check logs for details.' );
            } else {
                WP_CLI::success( 'Queue processing completed.' );
            }
            return;
        }

        WP_CLI::success( 'Jobs enqueued. Use --process-now to execute immediately or wait for cron.' );
    }
}

if ( defined('WP_CLI') && WP_CLI ) {
    WP_CLI::add_command('partjoo', 'PartJoo_CLI');
}
