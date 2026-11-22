<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

/**
 * Every option the plugin writes. Only its own names — nothing generic.
 */
$options = [
    'wssf_strict_mode',
    'wssf_ttl_user',
    'wssf_ttl_guest',
    'wssf_did_you_mean',
    'wssf_suggestions',
    'wssf_history_enabled',
    'wssf_search_post_types',
    'wssf_schema_version',
    'wssf_cache_gen',
    'wssf_index_updated_at',
    'wssf_semantic_prf',
    'wssf_semantic_synonyms',
    'wssf_semantic_typo',
    'wssf_semantic_enabled',
    'wssf_embedding_model',
    'wssf_openai_key',
    'wssf_synonyms',
    'wssf_term_balance_power',
    'wssf_post_type_weights',
    'wssf_autocomplete_enabled',
    'wssf_autocomplete_min_len',
    'wssf_autocomplete_max',
    'wssf_stopwords_enabled',
    'wssf_yara_enabled',
    'wssf_ioc_map_enabled',
    'wssf_related_enabled',
    'wssf_related_max',
    'wssf_minhash_enabled',
    'wssf_hdr_enabled',
    'wssf_hdr_xcto',
    'wssf_hdr_xfo',
    'wssf_hdr_referrer',
    'wssf_hdr_permissions',
    'wssf_csp_mode',
    'wssf_csp_script_src',
    'wssf_csp_style_src',
    'wssf_csp_img_src',
    'wssf_csp_connect_src',
    'wssf_cron_reindex_enabled',
    'wssf_cron_reindex_interval',
    'wssf_cron_batch_size',
    'wssf_cron_last_run',
    'wssf_cron_last_result',
    'wssf_reindex_batch_size',
    'wssf_index_async_save',
    'wssf_cleantalk_mode',
    'wssf_cleantalk_enabled',
    'wssf_redis_down_until',
];
foreach ( $options as $opt ) { delete_option( $opt ); }

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'wssf_search_history'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL without user input.

$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL, only the plugin's own transient names.
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wssf_%'
        OR option_name LIKE '_transient_timeout_wssf_%'"
);

$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL, only the plugin's own option names.
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_wssf_rl_%'
        OR option_name LIKE '_wssf_ct_lock_%'"
);

$tables = [
    (string) $wpdb->prefix . 'wssf_index',
    (string) $wpdb->prefix . 'wssf_terms',
    (string) $wpdb->prefix . 'wssf_embeddings',
    (string) $wpdb->prefix . 'wssf_stopwords',
    (string) $wpdb->prefix . 'wssf_ioc_map',
    (string) $wpdb->prefix . 'wssf_minhash',
];
foreach ( $tables as $table ) {
    if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) { continue; }
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL statement without placeholders; the table name is validated with /^[a-zA-Z0-9_]+$/.
}

// Indexes the plugin added to core tables.
$checks = [
    [ $wpdb->term_relationships, 'wssf_tt_object' ],
    [ $wpdb->term_taxonomy,      'wssf_taxonomy_term' ],
];
foreach ( $checks as $c ) {
    try {
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $c[0],
            $c[1]
        ) );
        if ( $count > 0 ) {
            $wpdb->query( "ALTER TABLE {$c[0]} DROP INDEX {$c[1]}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL statement without placeholders; both names are core $wpdb properties.
        }
    } catch ( \Throwable $e ) {}
}

if ( function_exists( 'wp_cache_flush' ) ) {
    wp_cache_flush();
}
