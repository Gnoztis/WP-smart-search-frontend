<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Ajax_Handler {

    public function __construct(
        private Query_Parser $parser,
        private Search_Engine $engine,
        private CleanTalk $cleantalk,
        private Security $security,
        private Layout_Corrector $corrector,
        private ?Autocomplete $autocomplete = null
    ) {}

    public function handle(): void {
        nocache_headers();
        if ( ! wp_doing_ajax() ) {
            wp_send_json_error( [ 'message' => __( 'Некорректный контекст.', 'wp-smart-search-frontend' ) ], 400 );
        }
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
        if ( $method !== 'POST' ) {
            wp_send_json_error( [ 'message' => __( 'Метод не разрешён.', 'wp-smart-search-frontend' ) ], 405 );
        }
        if ( ! $this->security->verify_request() ) {
            Security::log_event( 'nonce_failed', 'ajax:search' );
            wp_send_json_error( [ 'code' => 'wssf_bad_nonce', 'message' => __( 'Доступ запрещён.', 'wp-smart-search-frontend' ) ], 403 );
        }
        if ( ! $this->security->rate_limit_ok() ) {
            wp_send_json_error( [ 'message' => __( 'Слишком много запросов. Подождите немного.', 'wp-smart-search-frontend' ) ], 429 );
        }
        try { $this->do_handle(); }
        catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                error_log( '[WSSF] AJAX handle fatal: ' . sanitize_text_field( $e->getMessage() ) );
            }
            wp_send_json_error( [ 'code' => 'wssf_internal', 'message' => __( 'Ошибка поиска. Попробуйте позже.', 'wp-smart-search-frontend' ) ], 500 );
        }
    }

    private function do_handle(): void {
        $type = $this->parser->parse_type( $_POST['type'] ?? '' );
        $raw  = isset( $_POST['query'] ) ? wp_unslash( $_POST['query'] ) : '';
        if ( ! is_string( $raw ) ) {
            wp_send_json_error( [ 'message' => __( 'Некорректный запрос.', 'wp-smart-search-frontend' ) ], 400 );
        }
        $raw = Query_Parser::sanitize( $raw );

        $page   = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
        $cat_id = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
        $post_type_filter = $this->parser->parse_post_type( $_POST['post_type'] ?? '' );
        $tag_ids = isset( $_POST['tag_ids'] ) ? $this->parser->parse_ids( wp_unslash( $_POST['tag_ids'] ) ) : [];
        $exclude_tag_ids = isset( $_POST['exclude_tag_ids'] ) ? $this->parser->parse_ids( wp_unslash( $_POST['exclude_tag_ids'] ) ) : [];

        if ( ! empty( $tag_ids ) && ! empty( $exclude_tag_ids ) ) {
            $exclude_tag_ids = array_values( array_diff( $exclude_tag_ids, $tag_ids ) );
        }

        $has_filters = ! empty( $tag_ids ) || ! empty( $exclude_tag_ids ) || $cat_id > 0 || $post_type_filter !== '';
        if ( $raw === '' && ! $has_filters ) {
            wp_send_json_error( [ 'message' => __( 'Введите запрос или выберите фильтры.', 'wp-smart-search-frontend' ) ], 400 );
        }

        $groups = $this->parser->parse( $raw, true );
        if ( is_wp_error( $groups ) ) {
            wp_send_json_error( [ 'message' => $groups->get_error_message() ], 400 );
        }

        if ( $raw !== '' ) {
            $ct = $this->cleantalk->check_ajax( $raw, [
                'cat'             => $cat_id,
                'post_type'       => $post_type_filter,
                'tag_ids'         => $tag_ids,
                'exclude_tag_ids' => $exclude_tag_ids,
                'page'            => $page,
            ] );
            if ( ! empty( $ct['debug'] ) && (bool) apply_filters( 'wssf_diag_enabled', false ) ) {
                set_transient( 'wssf_diag_last', $ct['debug'], HOUR_IN_SECONDS );
            }
            if ( ! empty( $ct['blocked'] ) ) {
                wp_send_json_error( [ 'message' => $ct['message'] ], 403 );
            }
        }

        $payload = [ 'query' => $raw ];

        if ( $type === 'all' || $type === 'tags' ) {
            if ( $post_type_filter !== 'page' ) {
                $payload['tags'] = $this->engine->search_tags( $groups, $cat_id, $tag_ids, $post_type_filter, $exclude_tag_ids, $raw );
            } else {
                $payload['tags'] = [];
            }
        }

        if ( $type === 'all' || $type === 'posts' ) {
            $posts_result = $this->engine->search_fulltext( $groups, $page, $cat_id, $post_type_filter, $tag_ids, $exclude_tag_ids );
            $payload['posts'] = $posts_result;

            $posts_total = isset( $posts_result['total'] ) ? (int) $posts_result['total'] : 0;

            $dym_enabled = class_exists( 'WSSF_Admin_Settings' ) ? Admin_Settings::is_did_you_mean_enabled() : true;
            if ( $posts_total === 0 && $raw !== '' && $dym_enabled ) {
                try {
                    $suggestion = $this->build_suggestion( $raw, $cat_id, $post_type_filter, $tag_ids, $exclude_tag_ids );
                    if ( $suggestion !== null ) { $payload['suggestion'] = $suggestion; }
                } catch ( \Throwable $e ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                        error_log( '[WSSF] build_suggestion error: ' . sanitize_text_field( $e->getMessage() ) );
                    }
                }
            }
        }

        wp_send_json_success( $payload );
    }

    private function build_suggestion( string $raw, int $cat_id, string $post_type_filter, array $tag_ids, array $exclude_tag_ids ): ?array {
        $corrected = $this->corrector->correct( $raw );
        if ( $corrected === null ) { return null; }
        if ( mb_strtolower( $corrected ) === mb_strtolower( $raw ) ) { return null; }

        $parts = preg_split( '/\s+OR\s+/iu', $corrected );
        if ( is_array( $parts ) && count( $parts ) > 1 ) {
            foreach ( $parts as $part ) {
                $part = trim( (string) $part );
                if ( $part === '' ) { continue; }
                $groups = $this->parser->parse( $part, true );
                if ( is_wp_error( $groups ) || empty( $groups ) ) { continue; }
                $probe = $this->engine->search_fulltext( $groups, 1, $cat_id, $post_type_filter, $tag_ids, $exclude_tag_ids );
                if ( (int) ( $probe['total'] ?? 0 ) > 0 ) {
                    return [ 'query' => $part, 'total' => (int) $probe['total'] ];
                }
            }
            return null;
        }
        $corrected_groups = $this->parser->parse( $corrected, true );
        if ( is_wp_error( $corrected_groups ) || empty( $corrected_groups ) ) { return null; }
        $probe = $this->engine->search_fulltext( $corrected_groups, 1, $cat_id, $post_type_filter, $tag_ids, $exclude_tag_ids );
        $total = (int) ( $probe['total'] ?? 0 );
        if ( $total <= 0 ) { return null; }
        return [ 'query' => $corrected, 'total' => $total ];
    }

    public function handle_history(): void {
        nocache_headers();
        if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Auth required' ], 403 ); }
        if ( ! check_ajax_referer( Plugin::NONCE_ACTION, 'nonce', false ) ) {
            Security::log_event( 'nonce_failed', 'ajax:history' );
            wp_send_json_error( [ 'code' => 'wssf_bad_nonce', 'message' => 'Bad nonce' ], 403 );
        }
        if ( class_exists( 'WSSF_Admin_Settings' ) && ! Admin_Settings::is_history_enabled() ) {
            wp_send_json_error( [ 'message' => 'History disabled' ], 403 );
        }
        $user_id = get_current_user_id();
        $op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'fetch';

        if ( $op === 'save' ) {
            $raw_items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
            $arr       = is_string( $raw_items ) ? json_decode( $raw_items, true ) : null;
            if ( ! is_array( $arr ) ) { $arr = []; }

            $clean = [];
            foreach ( array_slice( $arr, 0, 10 ) as $item ) {
                if ( ! is_array( $item ) ) { continue; }
                $tags  = isset( $item['tags'] )  && is_array( $item['tags'] )  ? array_values( array_filter( array_map( 'absint', array_slice( $item['tags'],  0, 50 ) ) ) ) : [];
                $xtags = isset( $item['xtags'] ) && is_array( $item['xtags'] ) ? array_values( array_filter( array_map( 'absint', array_slice( $item['xtags'], 0, 50 ) ) ) ) : [];
                $q = isset( $item['query'] ) ? Query_Parser::sanitize( (string) $item['query'] ) : '';
                if ( mb_strlen( $q ) > 100 ) { $q = mb_substr( $q, 0, 100 ); }
                $clean[] = [
                    'query' => $q, 'cat' => isset( $item['cat'] ) ? absint( $item['cat'] ) : 0,
                    'type'  => isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '',
                    'tags' => $tags, 'xtags' => $xtags,
                    'tags_names'  => $this->sanitize_names_map( $item['tags_names']  ?? [] ),
                    'xtags_names' => $this->sanitize_names_map( $item['xtags_names'] ?? [] ),
                    'time' => isset( $item['time'] ) ? absint( $item['time'] ) : time(),
                ];
            }
            update_user_meta( $user_id, 'wssf_search_history', $clean );
            wp_send_json_success( [ 'items' => $clean ] );
        }

        if ( $op === 'clear' ) {
            delete_user_meta( $user_id, 'wssf_search_history' );
            wp_send_json_success( [ 'items' => [] ] );
        }
        $stored = get_user_meta( $user_id, 'wssf_search_history', true );
        wp_send_json_success( [ 'items' => is_array( $stored ) ? $stored : [] ] );
    }

    public function handle_suggest(): void {
        nocache_headers();
        if ( ! wp_doing_ajax() ) { wp_send_json_error( [ 'message' => 'Invalid context' ], 400 ); }
        if ( ! $this->security->verify_request() ) {
            Security::log_event( 'nonce_failed', 'ajax:suggest' );
            wp_send_json_error( [ 'code' => 'wssf_bad_nonce', 'message' => 'Forbidden' ], 403 );
        }
        if ( ! $this->security->rate_limit_ok() ) { wp_send_json_error( [ 'message' => 'Too many requests' ], 429 ); }
        if ( $this->autocomplete === null ) { wp_send_json_success( [ 'items' => [] ] ); }

        // A non-scalar "q" (q[]=x) must be rejected here: passing it to a
        // string parameter would be a TypeError and end the request with a
        // PHP fatal error on a public endpoint.
        $raw_q = isset( $_POST['q'] ) ? wp_unslash( $_POST['q'] ) : '';
        $q     = is_string( $raw_q ) ? Query_Parser::sanitize( $raw_q ) : '';
        if ( $q === '' ) { wp_send_json_success( [ 'items' => [], 'query' => '' ] ); }

        try { $items = $this->autocomplete->suggest( $q ); }
        catch ( \Throwable $e ) { $items = []; }

        wp_send_json_success( [ 'items' => $items, 'query' => $q ] );
    }

    private function sanitize_names_map( $map ): array {
        if ( ! is_array( $map ) ) { return []; }
        $map = array_slice( $map, 0, 20, true );
        $out = [];
        foreach ( $map as $id => $name ) {
            $id = absint( $id );
            if ( $id <= 0 ) { continue; }
            $name = sanitize_text_field( (string) $name );
            if ( $name === '' ) { continue; }
            if ( mb_strlen( $name ) > 50 ) { $name = mb_substr( $name, 0, 50 ); }
            $out[ (string) $id ] = $name;
        }
        return $out;
    }
}