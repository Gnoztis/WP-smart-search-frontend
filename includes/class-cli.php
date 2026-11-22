<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

final class WSSF_CLI {

    /**
     * Переиндексирует посты.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Размер батча. По умолчанию 500 (от 50 до 5000).
     *
     * [--mode=<mode>]
     * : all (по умолчанию) — все посты; missing — только недостающие.
     *
     * ## EXAMPLES
     *
     *     wp wssf reindex
     *     wp wssf reindex --batch=1000
     *     wp wssf reindex --mode=missing
     *
     * @when after_wp_load
     */
    public function reindex( $args, $assoc ): void {
        $batch = isset( $assoc['batch'] ) ? (int) $assoc['batch'] : 500;
        $batch = max( 50, min( 5000, $batch ) );
        $mode  = isset( $assoc['mode'] ) ? (string) $assoc['mode'] : 'all';
        if ( ! in_array( $mode, [ 'all', 'missing' ], true ) ) { $mode = 'all'; }

        $indexer = new WSSF_Indexer( new WSSF_Tokenizer( new WSSF_Morphology() ) );
        $indexer->create_table();
        $indexer->create_terms_table();
        if ( class_exists( 'WSSF_MinHash' ) ) {
            ( new WSSF_MinHash( WSSF_Plugin::instance()->cache ) )->create_table();
        }

        $total = ( $mode === 'missing' )
            ? $indexer->count_missing_posts()
            : $indexer->count_all_posts();

        WP_CLI::log( sprintf( 'Всего (%s): %d', $mode, $total ) );

        if ( $total === 0 ) {
            WP_CLI::success( 'Нечего индексировать.' );
            return;
        }

        $offset    = 0;
        $processed = 0;

        while ( $offset < $total ) {
            $ids = ( $mode === 'missing' )
                ? $indexer->get_missing_post_ids( $batch )
                : $indexer->get_all_post_ids( $offset, $batch );

            if ( empty( $ids ) ) { break; }

            foreach ( $ids as $id ) {
                try { $indexer->index_post( (int) $id ); }
                catch ( \Throwable $e ) { /* ignore */ }
                $processed++;
                if ( $processed % 100 === 0 ) {
                    WP_CLI::log( sprintf( 'Обработано: %d / %d', $processed, $total ) );
                }
            }

            if ( $mode === 'all' ) { $offset += count( $ids ); }
        }

        update_option( 'wssf_index_updated_at', current_time( 'mysql' ), true );

        $cache = WSSF_Plugin::instance()->cache;
        $cache->bump_generation();
        $cache->delete( 'wssf_index_count' );
        $cache->delete_transients_by_prefix( 'wssf_mh_sim_' );

        WP_CLI::success( sprintf( 'Готово: %d', $processed ) );
    }
}