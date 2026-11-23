<?php
declare(strict_types=1);


namespace WSSF;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Excerpt {

    public function make( string $content, array $words ): string {
        $content = trim( preg_replace( '/\s+/u', ' ', $content ) );
        if ( $content === '' ) { return ''; }
        $len = mb_strlen( $content );
        $pos = false;
        foreach ( $words as $w ) {
            $p = mb_stripos( $content, $w );
            if ( $p !== false && ( $pos === false || $p < $pos ) ) { $pos = $p; }
        }
        if ( $pos === false ) { $pos = 0; }
        $start   = max( 0, $pos - 60 );
        $excerpt = mb_substr( $content, $start, 200 );
        if ( $start > 0 ) { $excerpt = '…' . $excerpt; }
        if ( $start + 200 < $len ) { $excerpt .= '…'; }
        return $excerpt;
    }
}