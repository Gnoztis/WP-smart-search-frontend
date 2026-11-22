<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

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
];
foreach ( $options as $opt ) { delete_option( $opt ); }

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'wssf_search_history'" );

$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wssf_%'
        OR option_name LIKE '_transient_timeout_wssf_%'"
);

$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_wssf_rl_%'
        OR option_name LIKE '_wssf_ct_lock_%'"
);

$table = (string) $wpdb->prefix . 'wssf_index';
if ( preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}
$terms_table = (string) $wpdb->prefix . 'wssf_terms';
if ( preg_match( '/^[a-zA-Z0-9_]+$/', $terms_table ) ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$terms_table}`" );
}
$emb_table = (string) $wpdb->prefix . 'wssf_embeddings';
if ( preg_match( '/^[a-zA-Z0-9_]+$/', $emb_table ) ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$emb_table}`" );
}

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
            $wpdb->query( "ALTER TABLE {$c[0]} DROP INDEX {$c[1]}" );
        }
    } catch ( \Throwable $e ) {}
}

if ( function_exists( 'wp_cache_flush' ) ) {
    wp_cache_flush();
}