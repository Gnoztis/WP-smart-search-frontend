<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reciprocal Rank Fusion (RRF).
 *
 * Формула: score(d) = Σ_r 1 / (k + rank_r(d))
 * где k = 60 (стандартная константа из Cormack et al., 2009),
 * rank_r(d) — 1-индексированная позиция d в списке r.
 *
 * RRF не зависит от абсолютных значений score, поэтому надёжно
 * сливает разнородные ранжирования (BM25, PRF, fuzzy).
 */
final class Rank_Fusion {

    private const DEFAULT_K = 60;

    /**
     * @param array<int, array<int, int|string>> $rankings  список ранжирований (каждый — массив ID в порядке убывания релевантности)
     * @param int                                $k
     * @return array<int, array{id:int|string, score:float}>  отсортировано по score desc
     */
    public function fuse( array $rankings, int $k = self::DEFAULT_K ): array {
        $k = max( 1, $k );
        $scores = [];

        foreach ( $rankings as $ranking ) {
            if ( ! is_array( $ranking ) ) { continue; }
            $rank = 1;
            foreach ( $ranking as $id ) {
                $id = (string) $id; // нормализуем ключ
                if ( $id === '' ) { $rank++; continue; }
                $scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + 1.0 / ( $k + $rank );
                $rank++;
            }
        }

        arsort( $scores );
        $out = [];
        foreach ( $scores as $id => $score ) {
            $out[] = [ 'id' => $id, 'score' => $score ];
        }
        return $out;
    }

    /**
     * Удобная обёртка: принимает списки строк/чисел и возвращает
     * только упорядоченные ID.
     *
     * @param array<int, array<int, int|string>> $rankings
     * @return array<int, int|string>
     */
    public function fuse_ids( array $rankings, int $k = self::DEFAULT_K ): array {
        $fused = $this->fuse( $rankings, $k );
        $out   = [];
        foreach ( $fused as $row ) {
            $id = $row['id'];
            $out[] = is_numeric( $id ) ? (int) $id : $id;
        }
        return $out;
    }
}