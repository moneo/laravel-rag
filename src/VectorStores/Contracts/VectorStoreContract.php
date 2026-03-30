<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\VectorStores\Contracts;

use Illuminate\Support\Collection;

interface VectorStoreContract
{
    /**
     * Insert or update a vector with metadata.
     *
     * @param  string  $id  Unique identifier for the vector
     * @param  array<int, float>  $vector  The embedding vector
     * @param  array<string, mixed>  $metadata  Associated metadata
     */
    public function upsert(string $id, array $vector, array $metadata): void;

    /**
     * Perform a similarity search using a vector.
     *
     * @param  array<int, float>  $vector  The query vector
     * @param  int  $limit  Maximum number of results
     * @param  float  $threshold  Minimum similarity threshold (0.0 - 1.0)
     * @return Collection<int, array{id: string, score: float, metadata: array<string, mixed>}>
     */
    public function similaritySearch(array $vector, int $limit, float $threshold = 0.0): Collection;

    /**
     * Perform hybrid search combining semantic and full-text search.
     *
     * @param  string  $query  The text query for full-text search
     * @param  array<int, float>  $vector  The query vector for semantic search
     * @param  float  $semanticWeight  Weight for semantic results (0.0 - 1.0)
     * @param  float  $fulltextWeight  Weight for full-text results (0.0 - 1.0)
     * @param  int  $limit  Maximum number of results
     * @return Collection<int, array{id: string, score: float, metadata: array<string, mixed>}>
     */
    public function hybridSearch(string $query, array $vector, float $semanticWeight, float $fulltextWeight, int $limit): Collection;

    /**
     * Delete a vector by its ID.
     *
     * @param  string  $id  The vector ID to delete
     */
    public function delete(string $id): void;

    /**
     * Remove all vectors in a collection/table.
     *
     * @param  string  $collection  The collection/table name
     */
    public function flush(string $collection): void;

    /**
     * Set the table/collection to operate on.
     *
     * @param  string  $table  The table name
     */
    public function table(string $table): static;

    /**
     * Update only the embedding vector on an existing record.
     *
     * Unlike upsert(), this does NOT insert new records — it only updates
     * the embedding column on records that already exist. This avoids
     * NOT NULL constraint violations on user-defined columns (name, etc.).
     *
     * @param  string  $id  The record ID
     * @param  array<int, float>  $vector  The new embedding vector
     * @param  array<string, mixed>  $metadata  Optional metadata to update
     */
    public function updateEmbedding(string $id, array $vector, array $metadata = []): void;

    /**
     * Check if the vector store driver supports full-text search.
     */
    public function supportsFullTextSearch(): bool;
}
