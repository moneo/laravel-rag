<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Concerns;

use Illuminate\Support\Collection;
use Moneo\LaravelRag\Search\HybridSearch;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

/**
 * Add vector search capabilities to an Eloquent model.
 *
 * Model properties:
 * - string $vectorColumn = 'embedding'
 * - string $vectorDistance = 'cosine'
 * - string $fulltextColumn = 'content'
 */
trait HasVectorSearch
{
    /**
     * Get the vector column name.
     */
    public function getVectorColumn(): string
    {
        return $this->vectorColumn ?? 'embedding';
    }

    /**
     * Get the distance metric.
     */
    public function getVectorDistance(): string
    {
        return $this->vectorDistance ?? 'cosine';
    }

    /**
     * Get the full-text search column name.
     */
    public function getFulltextColumn(): string
    {
        return $this->fulltextColumn ?? 'content';
    }

    /**
     * Perform semantic similarity search.
     *
     * @param  array<int, float>  $vector  The query embedding vector
     * @param  int  $limit  Maximum number of results
     * @param  float  $threshold  Minimum similarity score (0.0 - 1.0)
     * @return Collection<int, array{id: string, score: float, metadata: array, content: string}>
     */
    public static function semanticSearch(array $vector, int $limit = 5, float $threshold = 0.0): Collection
    {
        $model = new static;
        $store = app(VectorStoreContract::class)->table($model->getTable());

        return $store->similaritySearch($vector, $limit, $threshold);
    }

    /**
     * Find nearest neighbours to a given model instance.
     *
     * @param  int  $limit  Maximum number of results
     * @param  float  $threshold  Minimum similarity score
     * @return Collection<int, array{id: string, score: float, metadata: array, content: string}>
     */
    public function nearestTo(int $limit = 5, float $threshold = 0.0): Collection
    {
        $vector = $this->getEmbeddingVector();

        if (empty($vector)) {
            return collect();
        }

        return static::semanticSearch($vector, $limit + 1, $threshold)
            ->filter(fn (array $result): bool => $result['id'] !== (string) $this->getKey())
            ->take($limit)
            ->values();
    }

    /**
     * Perform hybrid search combining semantic and full-text search.
     *
     * @param  string  $query  The text query
     * @param  array<int, float>  $vector  The query embedding vector
     * @param  float  $semanticWeight  Weight for semantic results (0.0 - 1.0)
     * @param  float  $fulltextWeight  Weight for full-text results (0.0 - 1.0)
     * @param  int  $limit  Maximum number of results
     * @return Collection<int, array{id: string, score: float, metadata: array, content: string}>
     */
    public static function hybridSearch(
        string $query,
        array $vector,
        float $semanticWeight = 0.7,
        float $fulltextWeight = 0.3,
        int $limit = 5,
    ): Collection {
        $model = new static;
        $hybridSearch = app(HybridSearch::class);

        return $hybridSearch->search(
            table: $model->getTable(),
            query: $query,
            vector: $vector,
            semanticWeight: $semanticWeight,
            fulltextWeight: $fulltextWeight,
            limit: $limit,
        );
    }

    /**
     * Scope: order by distance to a vector.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<int, float>  $vector
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithDistance($query, array $vector): mixed
    {
        $column = $this->getVectorColumn();
        $vectorString = '['.implode(',', $vector).']';

        return $query->selectRaw("*, 1 - ({$column} <=> '{$vectorString}'::vector) as distance")
            ->orderByRaw("{$column} <=> '{$vectorString}'::vector");
    }

    /**
     * Get the embedding vector from this model.
     *
     * @return array<int, float>
     */
    public function getEmbeddingVector(): array
    {
        $column = $this->getVectorColumn();
        $value = $this->getAttribute($column);

        if (is_string($value)) {
            // pgvector returns string like '[0.1,0.2,...]'
            return array_map(floatval(...), explode(',', trim($value, '[]')));
        }

        if (is_array($value)) {
            return $value;
        }

        return [];
    }
}
