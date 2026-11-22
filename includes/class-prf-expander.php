<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pseudo-Relevance Feedback (PRF) expander.
 *
 * Реализует две модели:
 *   1. Rocchio:  q_new = α·q + β·mean(relevant_docs)
 *      (без negative-компонента, т.к. PRF не имеет non-relevant).
 *   2. RM3:     P(t|q_new) = (1-λ)·P(t|q) + λ·P(t|R)
 *      где P(t|R) — вероятность термина в модели релевантности,
 *      построенной по топ-документам с Dirichlet-сглаживанием.
 *
 * Дополнительно извлекает биграммы (пары последовательных слов)
 * из топ-документов для повышения точности на фразовых запросах.
 *
 * Все вычисления — чистый PHP, без внешних библиотек.
 */
final class WSSF_PRF_Expander {

    /** Rocchio: вес исходного запроса. */
    private const ROCCHIO_ALPHA = 1.0;
    /** Rocchio: вес центроида релевантных документов. */
    private const ROCCHIO_BETA  = 0.75;

    /** RM3: вес модели релевантности (λ). */
    private const RM3_LAMBDA = 0.65;
    /** RM3: параметр Dirichlet-сглаживания (μ). */
    private const RM3_MU = 1500;

    /** Сколько топ-документов анализировать. */
    private const TOP_DOCS = 20;
    /** Сколько расширяющих униграмм добавлять. */
    private const MAX_UNIGRAMS = 8;
    /** Сколько расширяющих биграмм добавлять. */
    private const MAX_BIGRAMS = 5;
    /** Минимальная длина термина (символов). */
    private const MIN_TERM_LEN = 3;
    /** Максимальная длина термина (символов). */
    private const MAX_TERM_LEN = 40;

    /** @var array<string,int> Кеш частот терминов в корпусе (для P(t|C)). */
    private static array $corpus_freq_cache = [];

    public function __construct(
        private WSSF_Tokenizer $tokenizer
    ) {}

    /**
     * Расширяет запрос через PRF.
     *
     * @param array  $query_groups  стеммированные группы запроса
     * @param array  $top_post_ids  ID топ-документов из первого прогона
     * @param string $model         'rocchio' | 'rm3'
     * @return array{terms: string[], bigrams: array<int, array{0:string,1:string}>}
     */
    public function expand( array $query_groups, array $top_post_ids, string $model = 'rm3' ): array {
        $empty = [ 'terms' => [], 'bigrams' => [] ];

        $post_ids = array_values( array_filter( array_map( 'intval', $top_post_ids ), static fn( $v ) => $v > 0 ) );
        if ( count( $post_ids ) < 3 ) { return $empty; }

        $docs = $this->fetch_docs_terms( $post_ids );
        if ( empty( $docs ) ) { return $empty; }

        $query_terms = $this->flatten_query( $query_groups );
        $query_set   = [];
        foreach ( $query_terms as $t ) { $query_set[ (string) $t ] = true; }

        if ( $model === 'rocchio' ) {
            $scored = $this->rocchio_scores( $query_terms, $docs );
        } else {
            $scored = $this->rm3_scores( $query_terms, $docs );
        }

        // Фильтруем: не расширяем уже присутствующими в запросе терминами,
        // отбрасываем слишком короткие/длинные и технические ID.
        $terms = [];
        foreach ( $scored as $term => $score ) {
            $term = (string) $term;
            if ( $term === '' || isset( $query_set[ $term ] ) ) { continue; }
            $len = mb_strlen( $term );
            if ( $len < self::MIN_TERM_LEN || $len > self::MAX_TERM_LEN ) { continue; }
            if ( $this->tokenizer->is_technical_id( $term ) ) { continue; }
            $terms[ $term ] = $score;
        }
        arsort( $terms );
        $terms = array_slice( array_keys( $terms ), 0, self::MAX_UNIGRAMS );

        // Биграммы — из тех же топ-документов.
        $bigrams = $this->extract_bigrams( $docs, $query_set );
        $bigrams = array_slice( $bigrams, 0, self::MAX_BIGRAMS );

        return [
            'terms'   => array_values( array_map( 'strval', $terms ) ),
            'bigrams' => $bigrams,
        ];
    }

    /* ============================================================
     *  Rocchio
     * ============================================================ */

    /**
     * Rocchio: q_new = α·q + β·mean(relevant).
     * Возвращает map term => score.
     *
     * @param string[]            $query_terms
     * @param array<int,string[]> $docs  post_id => список стемов
     * @return array<string,float>
     */
    private function rocchio_scores( array $query_terms, array $docs ): array {
        $n = count( $docs );
        if ( $n === 0 ) { return []; }

        // TF-веса в каждом документе.
        $tf_per_doc = [];
        $df         = [];
        foreach ( $docs as $terms ) {
            $seen = [];
            foreach ( $terms as $t ) {
                $t = (string) $t;
                if ( $t === '' ) { continue; }
                $tf_per_doc[ $t ] = ( $tf_per_doc[ $t ] ?? 0 ) + 1;
                if ( ! isset( $seen[ $t ] ) ) {
                    $seen[ $t ] = true;
                    $df[ $t ] = ( $df[ $t ] ?? 0 ) + 1;
                }
            }
        }
        if ( empty( $tf_per_doc ) ) { return []; }

        // IDF (BM25-подобный) для взвешивания.
        $idf = [];
        foreach ( $df as $t => $freq ) {
            $idf[ $t ] = log( 1.0 + ( $n - $freq + 0.5 ) / ( $freq + 0.5 ) );
        }

        // Центроид релевантных документов: mean( tf * idf ).
        $centroid = [];
        foreach ( $tf_per_doc as $t => $tf ) {
            $centroid[ $t ] = ( $tf / $n ) * ( $idf[ $t ] ?? 0.0 );
        }

        // Нормализуем центроид.
        $norm = 0.0;
        foreach ( $centroid as $v ) { $norm += $v * $v; }
        $norm = sqrt( $norm );
        if ( $norm > 0 ) {
            foreach ( $centroid as $t => $v ) {
                $centroid[ $t ] = $v / $norm;
            }
        }

        // q_new = α·q + β·centroid. Компонент q — просто query_terms
        // с весом α·(1/|q|); для ранжирования достаточно β·centroid.
        $scores = [];
        foreach ( $centroid as $t => $v ) {
            $scores[ $t ] = self::ROCCHIO_BETA * $v;
        }
        return $scores;
    }

    /* ============================================================
     *  RM3
     * ============================================================ */

    /**
     * RM3: P(t|q_new) = (1-λ)·P(t|q) + λ·P(t|R).
     * P(t|R) вычисляется как смесь документных моделей с
     * Dirichlet-сглаживанием: P(t|d) = (tf + μ·P(t|C)) / (|d| + μ).
     * P(d) — равномерное (1/|D|).
     *
     * @param string[]            $query_terms
     * @param array<int,string[]> $docs
     * @return array<string,float>
     */
    private function rm3_scores( array $query_terms, array $docs ): array {
        $n = count( $docs );
        if ( $n === 0 ) { return []; }

        // Статистика корпуса (из top-docs как приближение).
        $corpus_tf = [];
        $total_len = 0;
        foreach ( $docs as $terms ) {
            foreach ( $terms as $t ) {
                $t = (string) $t;
                if ( $t === '' ) { continue; }
                $corpus_tf[ $t ] = ( $corpus_tf[ $t ] ?? 0 ) + 1;
                $total_len++;
            }
        }
        if ( $total_len === 0 ) { return []; }

        $p_t_C = [];
        foreach ( $corpus_tf as $t => $freq ) {
            $p_t_C[ $t ] = $freq / $total_len;
        }

        // P(t|R) = (1/|D|) · Σ_d P(t|d) · P(q|d)
        // P(q|d) — вероятность запроса в документной модели (для
        // простоты используем exp(-KL) или просто 1/|D|; здесь —
        // равномерное, как в каноническом RM3).
        $p_t_R = [];
        foreach ( $docs as $terms ) {
            $doc_len = count( $terms );
            if ( $doc_len === 0 ) { continue; }

            $doc_tf = [];
            foreach ( $terms as $t ) {
                $t = (string) $t;
                if ( $t === '' ) { continue; }
                $doc_tf[ $t ] = ( $doc_tf[ $t ] ?? 0 ) + 1;
            }

            // P(q|d): используем сглаженную вероятность запроса.
            // Упрощённо: 1 (вклад документа равномерный).
            $p_q_d = 1.0;

            foreach ( $doc_tf as $t => $tf ) {
                $mu = self::RM3_MU;
                $p_t_d = ( $tf + $mu * ( $p_t_C[ $t ] ?? 0.0 ) ) / ( $doc_len + $mu );
                $p_t_R[ $t ] = ( $p_t_R[ $t ] ?? 0.0 ) + ( $p_t_d * $p_q_d ) / $n;
            }
        }

        // P(t|q) — эмпирическая частота в запросе.
        $p_t_q = [];
        $q_len = max( 1, count( $query_terms ) );
        foreach ( $query_terms as $t ) {
            $t = (string) $t;
            if ( $t === '' ) { continue; }
            $p_t_q[ $t ] = ( $p_t_q[ $t ] ?? 0.0 ) + 1.0 / $q_len;
        }

        // Смесь: (1-λ)·P(t|q) + λ·P(t|R).
        $lambda = self::RM3_LAMBDA;
        $scores = [];
        $all_terms = array_unique( array_merge( array_keys( $p_t_q ), array_keys( $p_t_R ) ) );
        foreach ( $all_terms as $t ) {
            $scores[ $t ] = ( 1 - $lambda ) * ( $p_t_q[ $t ] ?? 0.0 )
                          + $lambda * ( $p_t_R[ $t ] ?? 0.0 );
        }
        return $scores;
    }

    /* ============================================================
     *  Биграммы
     * ============================================================ */

    /**
     * Извлекает топ-биграммы из топ-документов.
     * Биграмма — пара последовательных стемов, встречающаяся в
     * нескольких документах (защита от случайных пар).
     *
     * @param array<int,string[]> $docs
     * @param array<string,bool>  $query_set
     * @return array<int, array{0:string,1:string}>
     */
    private function extract_bigrams( array $docs, array $query_set ): array {
        $df = [];
        $total_docs = count( $docs );
        if ( $total_docs === 0 ) { return []; }

        foreach ( $docs as $terms ) {
            $terms = array_values( array_filter( array_map( 'strval', $terms ), static fn( $t ) => $t !== '' ) );
            $seen_in_doc = [];
            $len = count( $terms );
            for ( $i = 0; $i < $len - 1; $i++ ) {
                $a = $terms[ $i ];
                $b = $terms[ $i + 1 ];
                if ( mb_strlen( $a ) < self::MIN_TERM_LEN || mb_strlen( $b ) < self::MIN_TERM_LEN ) { continue; }
                $key = $a . ' ' . $b;
                if ( isset( $seen_in_doc[ $key ] ) ) { continue; }
                $seen_in_doc[ $key ] = true;
                $df[ $key ] = ( $df[ $key ] ?? 0 ) + 1;
            }
        }

        // Отбрасываем биграммы, встречающиеся только в одном документе
        // (шум) и содержащие термины запроса (они уже есть в запросе).
        $min_df = max( 2, (int) ceil( $total_docs * 0.15 ) );
        $candidates = [];
        foreach ( $df as $key => $freq ) {
            if ( $freq < $min_df ) { continue; }
            [ $a, $b ] = explode( ' ', $key, 2 );
            if ( isset( $query_set[ $a ] ) || isset( $query_set[ $b ] ) ) { continue; }
            $candidates[ $key ] = $freq;
        }

        arsort( $candidates );
        $out = [];
        foreach ( array_slice( array_keys( $candidates ), 0, self::MAX_BIGRAMS ) as $key ) {
            $parts = explode( ' ', $key, 2 );
            if ( count( $parts ) === 2 ) {
                $out[] = [ (string) $parts[0], (string) $parts[1] ];
            }
        }
        return $out;
    }

    /* ============================================================
     *  Загрузка документов
     * ============================================================ */

    /**
     * @param int[] $post_ids
     * @return array<int,string[]>  post_id => список стемов
     */
    private function fetch_docs_terms( array $post_ids ): array {
        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_index';
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) { return []; }

        $post_ids = array_values( array_filter( array_map( 'intval', $post_ids ), static fn( $v ) => $v > 0 ) );
        if ( empty( $post_ids ) ) { return []; }
        if ( count( $post_ids ) > self::TOP_DOCS ) {
            $post_ids = array_slice( $post_ids, 0, self::TOP_DOCS );
        }

        $in_ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        try {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, title_stem, content_stem, tags_stem FROM `{$table}` WHERE post_id IN ($in_ph)",
                $post_ids
            ) );
        } catch ( \Throwable $e ) {
            return [];
        }
        if ( ! is_array( $rows ) ) { return []; }

        $out = [];
        foreach ( $rows as $row ) {
            $text = (string) $row->title_stem . ' ' . (string) $row->tags_stem . ' ' . (string) $row->content_stem;
            $tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
            if ( ! is_array( $tokens ) ) { continue; }
            $out[ (int) $row->post_id ] = array_values( array_unique( array_map( 'strval', $tokens ) ) );
        }
        return $out;
    }

    /**
     * @param array $groups
     * @return string[]
     */
    private function flatten_query( array $groups ): array {
        $out = [];
        foreach ( $groups as $g ) {
            foreach ( $g as $t ) {
                $t = (string) $t;
                if ( $t !== '' ) { $out[] = $t; }
            }
        }
        return array_values( array_unique( $out ) );
    }
}