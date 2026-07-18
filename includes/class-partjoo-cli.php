<?php
if ( ! defined('ABSPATH') ) { exit; }

class PartJoo_CLI {
    /**
     * wp partjoo sync [--force] [--ids=1,2,3]
     */
    public function sync( $args, $assoc_args ) {
        $force = ! empty( $assoc_args['force'] );
        $ids   = [];
        if ( ! empty($assoc_args['ids']) ) {
            $ids = array_map('absint', explode(',', (string)$assoc_args['ids']));
        }
        if ( ! empty($ids) ) {
            $ok = PartJoo_Product_Sync::instance()->sync_products($ids, 'cli', $force);
        } else {
            $ok = PartJoo_Product_Sync::instance()->sync_changed_products('cli', $force);
        }
        if ( $ok ) {
            WP_CLI::success('Sync completed.');
        } else {
            WP_CLI::error('Sync failed.');
        }
    }
}

if ( defined('WP_CLI') && WP_CLI ) {
    WP_CLI::add_command('partjoo', 'PartJoo_CLI');
}
