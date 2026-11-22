<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WSSF_Morphology {

    private WSSF_Stemmer_Ru $ru;
    private WSSF_Stemmer_En $en;

    public function __construct() {
        $this->ru = new WSSF_Stemmer_Ru();
        $this->en = new WSSF_Stemmer_En();
    }

    /**
     * @param string $word
     * @param string $lang 'ru' | 'en' | '' (авто)
     */
    public function stem( string $word, string $lang = '' ): string {
        $word = mb_strtolower( trim( $word ) );
        if ( $word === '' ) { return ''; }

        if ( $lang === '' ) {
            $lang = preg_match( '/[а-яё]/u', $word ) ? 'ru' : 'en';
        }

        return $lang === 'ru' ? $this->ru->stem( $word ) : $this->en->stem( $word );
    }
}