<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WSSF_Initial_State {

    public function get_query(): string {
        $candidates = [];
        if ( isset( $_GET['s'] ) ) { $candidates[] = wp_unslash( $_GET['s'] ); }
        if ( isset( $_GET['q'] ) ) { $candidates[] = wp_unslash( $_GET['q'] ); }
        if ( empty( $candidates ) && ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $request_uri = (string) $_SERVER['REQUEST_URI'];
            $q_pos = strpos( $request_uri, '?' );
            if ( $q_pos !== false ) {
                $qs = substr( $request_uri, $q_pos + 1 );
                parse_str( $qs, $params );
                if ( isset( $params['s'] ) && is_string( $params['s'] ) ) {
                    $candidates[] = $params['s'];
                }
            }
        }
        foreach ( $candidates as $candidate ) {
            if ( ! is_string( $candidate ) ) { continue; }
            $q = WSSF_Query_Parser::sanitize( $candidate );
            if ( mb_strlen( $q ) > WSSF_Query_Parser::MAX_QUERY_LEN ) {
                $q = mb_substr( $q, 0, WSSF_Query_Parser::MAX_QUERY_LEN );
            }
            if ( $q !== '' ) { return $q; }
        }
        return '';
    }

    public function get_cat(): int {
        if ( ! isset( $_GET['cat'] ) ) { return 0; }
        return absint( wp_unslash( $_GET['cat'] ) );
    }

    public function get_type(): string {
        if ( ! isset( $_GET['pt'] ) ) { return ''; }
        $pt = sanitize_key( wp_unslash( $_GET['pt'] ) );
        if ( ! in_array( $pt, WSSF_Search_Engine::get_post_types(), true ) ) { return ''; }
        return $pt;
    }

    public function get_tags(): array {
        return $this->resolve( 'tags' );
    }

    public function get_exclude_tags(): array {
        return $this->resolve( 'xtags' );
    }

    private function resolve( string $param ): array {
        if ( ! isset( $_GET[ $param ] ) ) { return []; }
        $raw = wp_unslash( $_GET[ $param ] );
        $ids = [];
        if ( is_array( $raw ) ) {
            $ids = array_map( 'absint', $raw );
        } elseif ( is_string( $raw ) ) {
            $ids = array_map( 'absint', explode( ',', $raw ) );
        }
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        if ( empty( $ids ) ) { return []; }
        if ( count( $ids ) > 50 ) { $ids = array_slice( $ids, 0, 50 ); }

        $out = [];
        foreach ( $ids as $id ) {
            $term = get_term( $id, 'post_tag' );
            if ( $term instanceof \WP_Term ) {
                $out[] = [ 'id' => (int) $term->term_id, 'name' => (string) $term->name ];
            }
        }
        return $out;
    }
}