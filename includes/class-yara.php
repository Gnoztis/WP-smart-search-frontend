<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Извлечение имён YARA-правил из контента постов.
 *
 * Паттерн: /\brule\s+([A-Za-z_][A-Za-z0-9_]{0,63})\s*(?::[^{]*)?\{/
 *
 * YARA-правила в контенте ищутся:
 *   - при индексации поста (index_post) — извлекаем имена и добавляем
 *     в content_stem как токен "yara:NameOfRule";
 *   - при поиске — pure `yara:NameOfRule` запрос маршрутизируется
 *     в LIKE (по raw post_content), а mixed — во FULLTEXT с фразой.
 *
 * Автодополнение: тип "yara" — имена правил участвуют в prefix-scan.
 */
final class Yara {

    private const MAX_RULES_PER_POST = 50;

    public function is_enabled(): bool {
        return (bool) get_option( 'wssf_yara_enabled', 1 );
    }

    /**
     * Извлекает имена YARA-правил из текста.
     *
     * @return string[]  Уникальные имена правил.
     */
    public function extract_rule_names( string $text ): array {
        if ( ! $this->is_enabled() ) { return []; }
        $text = (string) $text;
        if ( $text === '' ) { return []; }

        // Быстрый pre-check, чтобы не гонять regex на текстах без YARA.
        if ( stripos( $text, 'rule ' ) === false ) { return []; }

        $names = [];
        if ( preg_match_all( '/\brule\s+([A-Za-z_][A-Za-z0-9_]{0,63})\s*(?::[^{]*)?\{/', $text, $m ) ) {
            foreach ( $m[1] as $name ) {
                $name = (string) $name;
                if ( $name === '' ) { continue; }
                $names[ $name ] = true;
                if ( count( $names ) >= self::MAX_RULES_PER_POST ) { break; }
            }
        }
        return array_keys( $names );
    }

    /**
     * Достаёт теги/семейства из meta-блока YARA:
     *   rule Foo : malware, ransomware { ... }
     *
     * @return string[]
     */
    public function extract_rule_tags( string $text ): array {
        if ( ! $this->is_enabled() ) { return []; }
        $text = (string) $text;
        if ( $text === '' ) { return []; }

        $tags = [];
        if ( preg_match_all( '/\brule\s+[A-Za-z_][A-Za-z0-9_]{0,63}\s*:\s*([^{]+)\{/', $text, $m ) ) {
            foreach ( $m[1] as $tag_str ) {
                foreach ( explode( ',', (string) $tag_str ) as $t ) {
                    $t = trim( mb_strtolower( (string) $t ) );
                    if ( $t === '' || mb_strlen( $t ) > 64 ) { continue; }
                    $tags[ $t ] = true;
                }
            }
        }
        return array_keys( $tags );
    }

    /**
     * Строит токены для индекса: "yara:name" для каждого правила.
     *
     * @return string[]
     */
    public function build_index_tokens( string $text ): array {
        $names = $this->extract_rule_names( $text );
        $tags  = $this->extract_rule_tags( $text );

        $tokens = [];
        foreach ( $names as $n ) { $tokens[] = 'yara:' . $n; }
        foreach ( $tags  as $t ) { $tokens[] = 'yaratag:' . $t; }

        return array_values( array_unique( $tokens ) );
    }

    public static function is_yara_token( string $term ): bool {
        return (bool) preg_match( '/^(yara|yaratag):[A-Za-z0-9_\-]{1,64}$/u', $term );
    }
}