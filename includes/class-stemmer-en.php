<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Стеммер Портера для английского языка (чистый PHP).
 * Адаптирован из публичной реализации Jon Abernathy / Richard Heyes.
 */
final class WSSF_Stemmer_En {

    public function stem( string $word ): string {
        if ( $word === '' ) { return ''; }
        $word = strtolower( $word );

        if ( substr( $word, -2 ) === "'s" ) {
            $word = substr( $word, 0, -2 );
        }
        $word = preg_replace( "/[^a-z0-9'.-]/", '', $word );

        $first = '';
        if ( strpos( $word, '-' ) !== false ) {
            $first = substr( $word, 0, strrpos( $word, '-' ) + 1 );
            $word  = substr( $word, strrpos( $word, '-' ) + 1 );
        }

        if ( strlen( $word ) > 2 ) {
            $word = $this->step1( $word );
            $word = $this->step2( $word );
            $word = $this->step3( $word );
            $word = $this->step4( $word );
            $word = $this->step5( $word );
        }

        return $first . $word;
    }

    private function step1( string $word ): string {
        // ---- Step 1a ----
        if ( substr( $word, -1 ) === 's' ) {
            if ( ! $this->replace( $word, 'sses', 'ss' )
                && ! $this->replace( $word, 'ies', 'i' )
                && ! $this->replace( $word, 'ss', 'ss' )
            ) {
                $this->replace( $word, 's', '' );
            }
        }

        // ---- Step 1b ----
        $eed_handled = false;
        if ( substr( $word, -2, 1 ) === 'e'
            && $this->replace( $word, 'eed', 'ee', 0 )
        ) {
            $eed_handled = true;
        }

        if ( ! $eed_handled ) {
            $suffix_removed = false;

            if ( substr( $word, -3 ) === 'ing'
                && preg_match( '/' . $this->regex_vowel() . '+/', substr( $word, 0, -3 ) )
                && $this->replace( $word, 'ing', '' )
            ) {
                $suffix_removed = true;
            } elseif ( substr( $word, -2 ) === 'ed'
                && preg_match( '/' . $this->regex_vowel() . '+/', substr( $word, 0, -2 ) )
                && $this->replace( $word, 'ed', '' )
            ) {
                $suffix_removed = true;
            }

            if ( $suffix_removed ) {
                if ( ! $this->replace( $word, 'at', 'ate' )
                    && ! $this->replace( $word, 'bl', 'ble' )
                    && ! $this->replace( $word, 'iz', 'ize' )
                ) {
                    if ( $this->double_consonant( $word )
                        && substr( $word, -2 ) !== 'll'
                        && substr( $word, -2 ) !== 'ss'
                        && substr( $word, -2 ) !== 'zz'
                    ) {
                        $word = substr( $word, 0, -1 );
                    } elseif ( $this->m( $word ) === 1 && $this->cvc( $word ) ) {
                        $word .= 'e';
                    }
                }
            }
        }

        // ---- Step 1c: (*v*) Y → I ----
        if ( substr( $word, -1 ) === 'y'
            && preg_match( '/' . $this->regex_vowel() . '+/', substr( $word, 0, -1 ) )
        ) {
            $this->replace( $word, 'y', 'i' );
        }

        return $word;
    }

    private function step2( string $word ): string {
        switch ( substr( $word, -2, 1 ) ) {
            case 'a':
                if ( $this->replace( $word, 'ational', 'ate', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'tional', 'tion', 0 ) ) { return $word; }
                break;
            case 'c':
                if ( $this->replace( $word, 'enci', 'ence', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'anci', 'ance', 0 ) ) { return $word; }
                break;
            case 'e':
                if ( $this->replace( $word, 'izer', 'ize', 0 ) ) { return $word; }
                break;
            case 'l':
                if ( $this->replace( $word, 'bli', 'ble', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'alli', 'al', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'entli', 'ent', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'eli', 'e', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'ousli', 'ous', 0 ) ) { return $word; }
                break;
            case 'o':
                if ( $this->replace( $word, 'ization', 'ize', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'isation', 'ize', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'ation', 'ate', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'ator', 'ate', 0 ) ) { return $word; }
                break;
            case 's':
                if ( $this->replace( $word, 'alism', 'al', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'iveness', 'ive', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'fulness', 'ful', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'ousness', 'ous', 0 ) ) { return $word; }
                break;
            case 't':
                if ( $this->replace( $word, 'aliti', 'al', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'iviti', 'ive', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'biliti', 'ble', 0 ) ) { return $word; }
                break;
        }
        return $word;
    }

    private function step3( string $word ): string {
        switch ( substr( $word, -2, 1 ) ) {
            case 'a':
                if ( $this->replace( $word, 'ical', 'ic', 0 ) ) { return $word; }
                break;
            case 's':
                if ( $this->replace( $word, 'ness', '', 0 ) ) { return $word; }
                break;
            case 't':
                if ( $this->replace( $word, 'icate', 'ic', 0 ) ) { return $word; }
                if ( $this->replace( $word, 'iciti', 'ic', 0 ) ) { return $word; }
                break;
            case 'u':
                if ( $this->replace( $word, 'ful', '', 0 ) ) { return $word; }
                break;
            case 'v':
                if ( $this->replace( $word, 'ative', '', 0 ) ) { return $word; }
                break;
            case 'z':
                if ( $this->replace( $word, 'alize', 'al', 0 ) ) { return $word; }
                break;
        }
        return $word;
    }

    private function step4( string $word ): string {
        switch ( substr( $word, -2, 1 ) ) {
            case 'a':
                if ( $this->replace( $word, 'al', '', 1 ) ) { return $word; }
                break;
            case 'c':
                if ( $this->replace( $word, 'ance', '', 1 ) ) { return $word; }
                if ( $this->replace( $word, 'ence', '', 1 ) ) { return $word; }
                break;
            case 'e':
                if ( $this->replace( $word, 'er', '', 1 ) ) { return $word; }
                break;
            case 'i':
                if ( $this->replace( $word, 'ic', '', 1 ) ) { return $word; }
                break;
            case 'l':
                if ( $this->replace( $word, 'able', '', 1 ) ) { return $word; }
                if ( $this->replace( $word, 'ible', '', 1 ) ) { return $word; }
                break;
            case 'n':
                if ( $this->replace( $word, 'ant', '', 1 ) ) { return $word; }
                if ( $this->replace( $word, 'ement', '', 1 ) ) { return $word; }
                if ( $this->replace( $word, 'ment', '', 1 ) ) { return $word; }
                if ( $this->replace( $word, 'ent', '', 1 ) ) { return $word; }
                break;
            case 'o':
                if ( substr( $word, -4 ) === 'sion' || substr( $word, -4 ) === 'tion' ) {
                    if ( $this->replace( $word, 'ion', '', 1 ) ) { return $word; }
                }
                if ( $this->replace( $word, 'ou', '', 1 ) ) { return $word; }
                break;
            case 's':
                if ( $this->replace( $word, 'ism', '', 1 ) ) { return $word; }
                break;
            case 't':
                if ( $this->replace( $word, 'ate', '', 1 ) ) { return $word; }
                if ( $this->replace( $word, 'iti', '', 1 ) ) { return $word; }
                break;
            case 'u':
                if ( $this->replace( $word, 'ous', '', 1 ) ) { return $word; }
                break;
            case 'v':
                if ( $this->replace( $word, 'ive', '', 1 ) ) { return $word; }
                break;
            case 'z':
                if ( $this->replace( $word, 'ize', '', 1 ) ) { return $word; }
                break;
        }
        return $word;
    }

    private function step5( string $word ): string {
        if ( substr( $word, -1 ) === 'e' ) {
            $short = substr( $word, 0, -1 );
            if ( $this->m( $short ) > 1 ) {
                $word = $short;
            } elseif ( $this->m( $short ) === 1 && ! $this->cvc( $short ) ) {
                $word = $short;
            }
        }
        if ( substr( $word, -2 ) === 'll' && $this->m( $word ) > 1 ) {
            $word = substr( $word, 0, -1 );
        }
        return $word;
    }

    private function regex_vowel(): string {
        return '(?:[aeiou]|(?<![aeiou])y)';
    }

    private function double_consonant( string $word ): bool {
        $len = strlen( $word );
        if ( $len < 2 ) { return false; }
        $last = substr( $word, -1 );
        $prev = substr( $word, -2, 1 );
        return $last === $prev && ! in_array( $last, [ 'a', 'e', 'i', 'o', 'u' ], true );
    }

    private function m( string $word ): int {
        $m = 0;
        $length = strlen( $word );
        $prev_c = false;
        for ( $i = 0; $i < $length; $i++ ) {
            $is_c = $this->is_consonant( $word, $i );
            if ( $is_c ) {
                if ( $m > 0 && ! $prev_c ) { $m += 0.5; }
            } else {
                if ( $prev_c || $m === 0 ) { $m += 0.5; }
            }
            $prev_c = $is_c;
        }
        return (int) floor( $m );
    }

    private function is_consonant( string $word, int $pos ): bool {
        $char = substr( $word, $pos, 1 );
        if ( in_array( $char, [ 'a', 'e', 'i', 'o', 'u' ], true ) ) { return false; }
        if ( $char === 'y' ) {
            if ( $pos === 0 ) { return true; }
            return ! $this->is_consonant( $word, $pos - 1 );
        }
        return true;
    }

    private function cvc( string $word ): bool {
        $len = strlen( $word );
        if ( $len < 3 ) { return false; }
        return $this->is_consonant( $word, $len - 1 )
            && ! $this->is_consonant( $word, $len - 2 )
            && $this->is_consonant( $word, $len - 3 )
            && ! in_array( substr( $word, -1 ), [ 'w', 'x', 'y' ], true );
    }

    private function replace( string &$word, string $suffix, string $replacement, int $m = 0 ): bool {
        $sl = strlen( $suffix );
        if ( substr( $word, -$sl ) === $suffix ) {
            $short = substr_replace( $word, '', -$sl );
            if ( $this->m( $short ) > $m ) {
                $word = $short . $replacement;
            }
            return true;
        }
        return false;
    }
}