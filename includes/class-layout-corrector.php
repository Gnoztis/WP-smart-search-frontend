<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WSSF_Layout_Corrector {

    private const EN_LOWER = 'qwertyuiop[]asdfghjkl;\'zxcvbnm,./`';
    private const RU_LOWER = 'йцукенгшщзхъфывапролджэячсмитьбю.ё';
    private const EN_UPPER = 'QWERTYUIOP{}ASDFGHJKL:"ZXCVBNM<>?~';
    private const RU_UPPER = 'ЙЦУКЕНГШЩЗХЪФЫВАПРОЛДЖЭЯЧСМИТЬБЮ,Ё';

    private WSSF_Tokenizer $tokenizer;

    public function __construct( WSSF_Tokenizer $tokenizer ) {
        $this->tokenizer = $tokenizer;
    }

    public function correct( string $raw ): ?string {
        $raw = trim( $raw );
        if ( $raw === '' ) { return null; }

        if ( $this->tokenizer->is_technical_id( $raw ) ) { return null; }

        $words = preg_split( '/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $words ) || empty( $words ) ) { return null; }

        $converted_words = [];
        $converted_any   = false;

        foreach ( $words as $word ) {
            if ( $this->tokenizer->is_technical_id( $word ) ) {
                $converted_words[] = $word;
                continue;
            }

            $direction = $this->detect_direction( $word );
            if ( $direction === null ) {
                $converted_words[] = $word;
                continue;
            }

            $converted = $direction === 'en2ru'
                ? $this->convert( $word, self::EN_LOWER, self::RU_LOWER, self::EN_UPPER, self::RU_UPPER )
                : $this->convert( $word, self::RU_LOWER, self::EN_LOWER, self::RU_UPPER, self::EN_UPPER );

            if ( $converted === $word ) {
                $converted_words[] = $word;
                continue;
            }

            // has_vowels — только для en→ru. Для ru→en НЕ проверяем:
            // короткие аббревиатуры (php/css/js/sql) на русской
            // раскладке дают «зрз/ску/ос/ыкд», в латинском варианте
            // гласных нет, но конверсия должна работать.
            if ( $direction === 'en2ru' && ! $this->has_vowels( $converted, 'ru' ) ) {
                $converted_words[] = $word;
                continue;
            }

            $converted_words[] = $converted;
            $converted_any     = true;
        }

        if ( ! $converted_any ) { return null; }

        $result = implode( ' ', $converted_words );
        if ( $result === $raw ) { return null; }

        return $result;
    }

    private function detect_direction( string $word ): ?string {
        if ( preg_match( '/\d/', $word ) ) { return null; }

        $has_latin = (bool) preg_match( '/[a-z]/i', $word );

        // Только буквы кириллицы, без спецсимволов.
        // Раньше сюда были включены [ ] ; ' , . ` { } : " < > ? ~ —
        // это ломало конверсию для слов, содержащих эти символы
        // (например «hj,jnf» = «работа»).
        $has_cyr = (bool) preg_match( '/[а-яё]/iu', $word );

        if ( $has_latin && ! $has_cyr ) { return 'en2ru'; }
        if ( $has_cyr && ! $has_latin ) { return 'ru2en'; }
        return null;
    }

    private function convert( string $word, string $from_lower, string $to_lower, string $from_upper, string $to_upper ): string {
        $map = [];
        $from_all = $from_lower . $from_upper;
        $to_all   = $to_lower   . $to_upper;
        $len = mb_strlen( $from_all );
        for ( $i = 0; $i < $len; $i++ ) {
            $f = mb_substr( $from_all, $i, 1 );
            $t = mb_substr( $to_all, $i, 1 );
            if ( $f !== '' ) { $map[ $f ] = $t; }
        }
        return strtr( $word, $map );
    }

    private function has_vowels( string $word, string $lang ): bool {
        $vowels = $lang === 'ru' ? 'аеиоуыэюяё' : 'aeiouy';
        return (bool) preg_match( '/[' . preg_quote( $vowels, '/' ) . ']/iu', $word );
    }
}