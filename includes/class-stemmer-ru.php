<?php
declare(strict_types=1);

namespace WSSF;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Стеммер Портера для русского языка (чистый PHP, без словарей).
 * Адаптирован из публичной реализации Lingua_Stem_Ru
 * (https://github.com/neonxp/Stemmer).
 */
final class Stemmer_Ru {

    private string $VOWEL            = '/аеиоуыэюя/ui';
    private string $PERFECTIVEGROUND = '/((ив|ивши|ившись|ыв|ывши|ывшись)|((?<=[ая])(в|вши|вшись)))$/ui';
    private string $REFLEXIVE        = '/(с[яь])$/ui';
    private string $ADJECTIVE        = '/(ее|ие|ые|ое|ими|ыми|ей|ий|ый|ой|ем|им|ым|ом|его|ого|ему|ому|их|ых|ую|юю|ая|яя|ою|ею)$/ui';
    private string $PARTICIPLE       = '/((ивш|ывш|ующ)|((?<=[ая])(ем|нн|вш|ющ|щ)))$/ui';
    private string $VERB             = '/((ила|ыла|ена|ейте|уйте|ите|или|ыли|ей|уй|ил|ыл|им|ым|ен|ило|ыло|ено|ят|ует|уют|ит|ыт|ены|ить|ыть|ишь|ую|ю)|((?<=[ая])(ла|на|ете|йте|ли|й|л|ем|н|ло|но|ет|ют|ны|ть|ешь|нно)))$/ui';
    private string $NOUN             = '/(а|ев|ов|ие|ье|е|иями|ями|ами|еи|ии|и|ией|ей|ой|ий|й|иям|ям|ием|ем|ам|ом|о|у|ах|иях|ях|ы|ь|ию|ью|ю|ия|ья|я)$/ui';
    private string $RVRE             = '/^(.*?[аеиоуыэюя])(.*)$/ui';
    private string $DERIVATIONAL     = '/[^аеиоуыэюя][аеиоуыэюя]+[^аеиоуыэюя]+[аеиоуыэюя].*(?<=о)сть?$/ui';

    public function stem( string $word ): string {
        $word = mb_strtolower( $word );
        $word = str_replace( 'ё', 'е', $word ); // «ёлки» → «елки»
        $stem = $word;

        do {
            if ( ! preg_match( $this->RVRE, $word, $p ) ) { break; }
            $start = $p[1];
            $RV    = $p[2];
            if ( $RV === '' ) { break; }

            if ( ! $this->s( $RV, $this->PERFECTIVEGROUND, '' ) ) {
                $this->s( $RV, $this->REFLEXIVE, '' );

                if ( $this->s( $RV, $this->ADJECTIVE, '' ) ) {
                    $this->s( $RV, $this->PARTICIPLE, '' );
                } else {
                    if ( ! $this->s( $RV, $this->VERB, '' ) ) {
                        $this->s( $RV, $this->NOUN, '' );
                    }
                }
            }

            $this->s( $RV, '/и$/ui', '' );

            if ( $this->m( $RV, $this->DERIVATIONAL ) ) {
                $this->s( $RV, '/ость?$/ui', '' );
            }

            if ( ! $this->s( $RV, '/ь$/ui', '' ) ) {
                $this->s( $RV, '/ейше?/ui', '' );
                $this->s( $RV, '/нн$/ui', 'н' );
            }

            $stem = $start . $RV;
        } while ( false );

        return $stem;
    }

    private function s( string &$s, string $re, string $to ): bool {
        $orig = $s;
        $s    = preg_replace( $re, $to, $s );
        return $orig !== $s;
    }

    private function m( string $s, string $re ): bool {
        return (bool) preg_match( $re, $s );
    }
}