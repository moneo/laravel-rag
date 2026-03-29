<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Search;

use Illuminate\Support\Collection;
use Moneo\LaravelRag\Support\RagLogger;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

class HybridSearch
{
    /**
     * @param  VectorStoreContract  $vectorStore  The vector store instance
     * @param  int  $rrfK  Reciprocal Rank Fusion constant (default 60)
     */
    public function __construct(
        protected readonly VectorStoreContract $vectorStore,
        protected readonly int $rrfK = 60,
    ) {}

    /**
     * Perform hybrid search combining semantic and full-text with RRF.
     *
     * @param  string  $table  The table to search
     * @param  string  $query  The text query
     * @param  array<int, float>  $vector  The query embedding
     * @param  float  $semanticWeight  Weight for semantic results
     * @param  float  $fulltextWeight  Weight for full-text results
     * @param  int  $limit  Maximum results
     * @return Collection<int, array{id: string, score: float, metadata: array, content: string}>
     */
    public function search(
        string $table,
        string $query,
        array $vector,
        float $semanticWeight = 0.7,
        float $fulltextWeight = 0.3,
        int $limit = 5,
    ): Collection {
        $store = $this->vectorStore->table($table);

        // If the store supports hybrid natively, delegate
        if ($store->supportsFullTextSearch()) {
            return $store->hybridSearch($query, $vector, $semanticWeight, $fulltextWeight, $limit);
        }

        // Fallback: pure semantic search with warning
        RagLogger::warning('HybridSearch: falling back to pure semantic search — driver does not support full-text', [
            'table' => $table,
            'driver' => $store::class,
        ]);

        return $store->similaritySearch($vector, $limit);
    }

    /**
     * Merge two ranked result sets using Reciprocal Rank Fusion.
     *
     * @param  Collection<int, array{id: string, score: float, metadata: array, content: string}>  $semanticResults
     * @param  Collection<int, array{id: string, score: float, metadata: array, content: string}>  $fulltextResults
     * @return Collection<int, array{id: string, score: float, metadata: array, content: string}>
     */
    public function mergeWithRRF(
        Collection $semanticResults,
        Collection $fulltextResults,
        float $semanticWeight = 0.7,
        float $fulltextWeight = 0.3,
        int $limit = 5,
    ): Collection {
        $scores = [];
        $items = [];

        // Score semantic results
        foreach ($semanticResults->values() as $rank => $result) {
            $id = $result['id'];
            $rrfScore = $semanticWeight * (1.0 / ($this->rrfK + $rank + 1));
            $scores[$id] = ($scores[$id] ?? 0) + $rrfScore;
            $items[$id] = $result;
        }

        // Score fulltext results
        foreach ($fulltextResults->values() as $rank => $result) {
            $id = $result['id'];
            $rrfScore = $fulltextWeight * (1.0 / ($this->rrfK + $rank + 1));
            $scores[$id] = ($scores[$id] ?? 0) + $rrfScore;
            $items[$id] ??= $result;
        }

        // Sort by combined RRF score
        arsort($scores);

        return collect(array_slice(array_keys($scores), 0, $limit))
            ->map(fn (string $id): array => array_merge($items[$id], ['score' => $scores[$id]]))
            ->values();
    }
}
