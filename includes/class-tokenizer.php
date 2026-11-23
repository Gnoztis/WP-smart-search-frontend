<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Tokenizer {

    private const IOC_PATTERNS = [
        '/(?:https?|ftp):\/\/[^\s<>"\']+/iu',
        '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,63}/iu',
        '/\b[a-z2-7]{56}\.onion\b/iu',
        '/\b[a-z2-7]{16}\.onion\b/iu',
        '/\b[a-z2-7]{52}\.b32\.i2p\b/iu',
        '/\b[a-z0-9\-]+\.i2p\b/iu',
        '/(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:){1,7}:|(?:[0-9a-f]{1,4}:){1,6}:[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:){1,5}(?::[0-9a-f]{1,4}){1,2}|(?:[0-9a-f]{1,4}:){1,4}(?::[0-9a-f]{1,4}){1,3}|(?:[0-9a-f]{1,4}:){1,3}(?::[0-9a-f]{1,4}){1,4}|(?:[0-9a-f]{1,4}:){1,2}(?::[0-9a-f]{1,4}){1,5}|[0-9a-f]{1,4}:(?::[0-9a-f]{1,4}){1,6}|:(?:(?::[0-9a-f]{1,4}){1,7}|:)/iu',
        '/(?<![0-9.])(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?(?:\/\d{1,2})?(?![0-9.])/u',
        '/\b(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}:\d{1,5}\b/iu',
        '/\b(?:HKLM|HKCU|HKCR|HKU|HKCC|HKEY_(?:LOCAL_MACHINE|CURRENT_USER|CLASSES_ROOT|USERS|CURRENT_CONFIG))\\\\[^\s<>"\']+/iu',
        '/\{[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}/iu',
        '/(?<![0-9a-f\-])[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![0-9a-f\-])/iu',
        '/\b0x[a-f0-9]{40}\b/iu',
        '/\bbc1[a-z0-9]{25,90}\b/iu',
        '/\b[13][a-km-zA-HJ-NP-Z1-9]{25,34}\b/u',
        '/\bltc1[a-z0-9]{25,90}\b/iu',
        '/\b[LM][a-km-zA-HJ-NP-Z1-9]{25,34}\b/u',
        '/\b[48][0-9AB][1-9A-HJ-NP-Za-km-z]{93}\b/u',
        '/\bT[a-zA-Z0-9]{33}\b/u',
        '/\br[1-9A-HJ-NP-Za-km-z]{24,34}\b/u',
        '/\b\d{1,}:[a-zA-Z0-9\/+]{6,}:[a-zA-Z0-9\/+]{6,}\b/u',
        '/\b[a-f0-9]{128}\b/iu',
        '/\b[a-f0-9]{64}\b/iu',
        '/\b[a-f0-9]{40}\b/iu',
        '/\b[a-f0-9]{32}\b/iu',
        '/CVE-\d{4}-\d{4,}/iu',
        '/BDU:\d{4}-\d+/iu',
        '/\bT\d{4}(?:\.\d{3})?\b/iu',
        '/\bAS\d{1,10}\b/iu',
        '/(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}/iu',
    ];

    /** Одна проверка вместо 25 regex: есть ли в тексте хоть намёк на IOC. */
    private const IOC_HINT_REGEX = '/(?::\/\/|@|CVE-|BDU:|T\d{4}|[0-9a-f]{32,}|(?:\d{1,3}\.){3}\d{1,3})/i';

    /** Быстрая проверка на уровне токена: есть ли цифра или спецсимвол (IOC-признак). */
    private const IOC_TOKEN_HINT_REGEX = '/[0-9]|[:@._\-\/]/';

    private Morphology $morphology;

    /** Кеш стоп-слов на время жизни экземпляра (обычно один запрос). */
    private ?array $stopwords_cache = null;

    public function __construct( Morphology $morphology ) {
        $this->morphology = $morphology;
    }

    /**
     * Возвращает токены и отдельно список IOC (чтобы не делать is_technical_id повторно).
     *
     * @return array{tokens: string[], iocs: string[]}
     */
    public function tokenize_with_iocs( string $text, bool $do_stem = true ): array {
        $text = wp_strip_all_tags( $text );

        $placeholders = [];
        $iocs         = [];
        $counter      = 0;

        // Pre-check: если в тексте нет ни одного маркера IOC — не гоняем 25 regex.
        if ( preg_match( self::IOC_HINT_REGEX, $text ) ) {
            foreach ( self::IOC_PATTERNS as $pattern ) {
                $text = preg_replace_callback(
                    $pattern,
                    static function ( $m ) use ( &$placeholders, &$iocs, &$counter ) {
                        $key = 'WSSFPHX' . $counter . 'XHPFSSW';
                        $counter++;
                        $placeholders[ $key ] = $m[0];
                        $iocs[] = $m[0];
                        return ' ' . $key . ' ';
                    },
                    $text
                );
                if ( ! is_string( $text ) ) { $text = (string) $text; }
            }
        }

        $raw = preg_split( '/[\s\p{P}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $raw ) ) { $raw = []; }

        $stopwords = $this->get_stopwords();

        $out = [];
        foreach ( $raw as $token ) {
            $token = trim( (string) $token );
            if ( $token === '' ) { continue; }

            if ( isset( $placeholders[ $token ] ) ) {
                $out[] = $placeholders[ $token ];
                continue;
            }

            if ( ! $do_stem ) {
                $out[] = mb_strtolower( $token );
                continue;
            }

            $stem = $this->morphology->stem( $token );
            if ( $stem === '' ) { continue; }

            if ( ! empty( $stopwords ) && isset( $stopwords[ $stem ] ) ) { continue; }

            $out[] = $stem;
        }

        return [
            'tokens' => array_values( array_unique( $out ) ),
            'iocs'   => array_values( array_unique( $iocs ) ),
        ];
    }

    public function tokenize( string $text, bool $do_stem = true ): array {
        $result = $this->tokenize_with_iocs( $text, $do_stem );
        return $result['tokens'];
    }

    public function is_technical_id( string $token ): bool {
        $token = trim( $token );
        if ( $token === '' ) { return false; }

        // YARA-токены — быстрая проверка вперёд.
        if ( class_exists( 'WSSF_Yara' ) && Yara::is_yara_token( $token ) ) {
            return true;
        }

        // Pre-check: слишком короткий — не IOC.
        if ( mb_strlen( $token ) < 5 ) { return false; }

        // Pre-check: нет цифр и спецсимволов — не IOC (только буквы).
        if ( ! preg_match( self::IOC_TOKEN_HINT_REGEX, $token ) ) { return false; }

        foreach ( self::IOC_PATTERNS as $pattern ) {
            if ( preg_match( $pattern, $token, $m ) && $m[0] === $token ) { return true; }
        }
        return false;
    }

    public function groups_to_boolean_string( array $groups ): string {
        $synonyms = [];
        if ( class_exists( 'WSSF_Admin_Settings' ) && method_exists( 'WSSF_Admin_Settings', 'get_synonyms' ) ) {
            $synonyms = Admin_Settings::get_synonyms();
        }

        $parts = [];
        foreach ( $groups as $group ) {
            foreach ( $group as $token ) {
                $token = (string) $token;
                if ( $token === '' ) { continue; }

                if ( $this->is_technical_id( $token ) ) {
                    $chunks = preg_split( '/[^\p{L}\p{N}]+/u', $token, -1, PREG_SPLIT_NO_EMPTY );
                    if ( ! is_array( $chunks ) ) { $chunks = []; }
                    $usable = [];
                    foreach ( $chunks as $chunk ) {
                        $chunk = (string) $chunk;
                        if ( mb_strlen( $chunk ) < 3 ) { continue; }
                        $chunk = preg_replace( '/[+\-><\(\)~*\"@\\\\%]/u', '', $chunk );
                        if ( $chunk !== '' ) { $usable[] = $chunk; }
                    }
                    if ( ! empty( $usable ) ) {
                        $parts[] = '+"' . implode( ' ', $usable ) . '"';
                    }
                    continue;
                }

                $clean = preg_replace( '/[+\-><\(\)~*\"@\\\\%]+/u', ' ', $token );
                $clean = trim( (string) $clean );
                if ( $clean === '' ) { continue; }

                if ( strpos( $clean, ' ' ) !== false ) {
                    $parts[] = '+"' . $clean . '"';
                } else {
                    $parts[] = '+' . $clean . '*';
                }

                $key = mb_strtolower( $clean );
                if ( isset( $synonyms[ $key ] ) ) {
                    foreach ( $synonyms[ $key ] as $syn ) {
                        $syn = (string) $syn;
                        if ( $syn === '' ) { continue; }
                        $syn_clean = preg_replace( '/[+\-><\(\)~*\"@\\\\%]+/u', ' ', $syn );
                        $syn_clean = trim( (string) $syn_clean );
                        if ( $syn_clean === '' ) { continue; }
                        if ( strpos( $syn_clean, ' ' ) !== false ) {
                            $parts[] = '+"' . $syn_clean . '"';
                        } else {
                            $parts[] = $syn_clean . '*';
                        }
                    }
                }
            }
        }
        return implode( ' ', $parts );
    }

    /* ============================================================
     *  Внутренние
     * ============================================================ */

    private function get_stopwords(): array {
        if ( $this->stopwords_cache !== null ) { return $this->stopwords_cache; }
        if ( ! class_exists( 'WSSF_Stopwords' ) ) {
            $this->stopwords_cache = [];
            return [];
        }
        try {
            $sw = Stopwords::instance();
            $this->stopwords_cache = $sw->is_enabled() ? $sw->get_all() : [];
        } catch ( \Throwable $e ) {
            $this->stopwords_cache = [];
        }
        return $this->stopwords_cache;
    }
}