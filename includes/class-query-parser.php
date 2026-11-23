<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Query_Parser {

    public const MIN_LEN       = 3;
    public const MIN_WORD_LEN  = 3;
    public const MAX_QUERY_LEN = 200;
    public const MAX_WORDS     = 10;
    public const MAX_WORD_LEN  = 100;

    private Tokenizer $tokenizer;

    public function __construct( Tokenizer $tokenizer ) {
        $this->tokenizer = $tokenizer;
    }

    public static function sanitize( string $raw ): string {
        $raw = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw );
        if ( ! is_string( $raw ) ) { return ''; }
        $raw = preg_replace( '/\s+/u', ' ', $raw );
        if ( ! is_string( $raw ) ) { return ''; }
        return trim( $raw );
    }

    public function parse( string $raw, bool $allow_empty = false ) {
        $raw = self::sanitize( $raw );
        if ( mb_strlen( $raw ) > self::MAX_QUERY_LEN ) {
            $raw = mb_substr( $raw, 0, self::MAX_QUERY_LEN );
        }
        if ( $raw === '' ) {
            if ( $allow_empty ) { return []; }
            return new \WP_Error( 'empty', __( 'Введите поисковый запрос.', 'wp-smart-search-frontend' ) );
        }
        if ( mb_strlen( $raw ) < self::MIN_LEN ) {
            if ( $allow_empty ) { return []; }
            return new \WP_Error( 'too_short', sprintf( __( 'Введите не менее %d символов.', 'wp-smart-search-frontend' ), self::MIN_LEN ) );
        }

        $tokens = $this->tokenize_with_quotes( $raw );
        if ( empty( $tokens ) ) {
            if ( $allow_empty ) { return []; }
            return new \WP_Error( 'invalid', __( 'Некорректный запрос.', 'wp-smart-search-frontend' ) );
        }

        $groups = [];
        $current = [];
        foreach ( $tokens as $tok ) {
            if ( $tok === 'OR' ) {
                if ( ! empty( $current ) ) { $groups[] = $current; $current = []; }
                continue;
            }
            $clean = trim( $tok, '"' );
            if ( $clean !== '' ) { $current[] = $clean; }
        }
        if ( ! empty( $current ) ) { $groups[] = $current; }

        $groups = array_slice( $groups, 0, 10 );
        $total = 0;
        foreach ( $groups as &$g ) {
            $g = array_slice( $g, 0, self::MAX_WORDS );
            $total += count( $g );
        }
        unset( $g );

        if ( $total === 0 ) {
            if ( $allow_empty ) { return []; }
            return new \WP_Error( 'invalid', __( 'Некорректный запрос.', 'wp-smart-search-frontend' ) );
        }

        $stemmed_groups = [];
        foreach ( $groups as $group ) {
            $stemmed_group = [];
            foreach ( $group as $token ) {
                if ( $this->tokenizer->is_technical_id( $token ) ) {
                    $stemmed_group[] = $token;
                } else {
                    $tokens_stem = $this->tokenizer->tokenize( $token, true );
                    $stemmed_group[] = $tokens_stem[0] ?? $token;
                }
            }
            $stemmed_groups[] = $stemmed_group;
        }

        return $stemmed_groups;
    }

    private function tokenize_with_quotes( string $raw ): array {
        $tokens = [];
        $len = mb_strlen( $raw );
        $i = 0;
        while ( $i < $len ) {
            $ch = mb_substr( $raw, $i, 1 );
            if ( $ch === ' ' ) { $i++; continue; }
            if ( $ch === '"' ) {
                $j = $i + 1;
                $phrase = '';
                while ( $j < $len ) {
                    $c = mb_substr( $raw, $j, 1 );
                    if ( $c === '"' ) { break; }
                    $phrase .= $c;
                    $j++;
                }
                if ( $phrase !== '' ) { $tokens[] = '"' . $phrase . '"'; }
                $i = $j + 1;
                continue;
            }
            $start = $i;
            while ( $i < $len && mb_substr( $raw, $i, 1 ) !== ' ' ) { $i++; }
            $word = mb_substr( $raw, $start, $i - $start );
            if ( strcasecmp( $word, 'OR' ) === 0 || $word === '|' ) {
                $tokens[] = 'OR';
            } else {
                $tokens[] = $word;
            }
        }
        return $tokens;
    }

    public function parse_type( $value ): string {
        if ( ! is_string( $value ) ) { return 'all'; }
        $type = sanitize_key( $value );
        return in_array( $type, [ 'all', 'tags', 'posts' ], true ) ? $type : 'all';
    }

    public function parse_ids( $value ): array {
        $ids = [];
        if ( is_array( $value ) ) { $ids = array_map( 'absint', $value ); }
        elseif ( is_string( $value ) ) { $ids = array_map( 'absint', explode( ',', $value ) ); }
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        if ( count( $ids ) > 50 ) { $ids = array_slice( $ids, 0, 50 ); }
        return $ids;
    }

    public function parse_post_type( $value ): string {
        if ( ! is_string( $value ) ) { return ''; }
        $pt = sanitize_key( $value );
        if ( ! in_array( $pt, Search_Engine::get_post_types(), true ) ) { return ''; }
        return $pt;
    }
}