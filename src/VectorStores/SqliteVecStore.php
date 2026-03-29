<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\VectorStores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Moneo\LaravelRag\Security\VectorValidator;
use Moneo\LaravelRag\Support\RagLogger;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

class SqliteVecStore implements VectorStoreContract
{
    protected string $table = 'documents';

    /**
     * @param  string  $connection  The database connection name
     * @param  int  $dimensions  The vector dimensions
     */
    public function __construct(
        protected readonly string $connection,
        protected readonly int $dimensions,
    ) {}

    /**
     * @inheritDoc
     */
    public function table(string $table): static
    {
        $this->validateTableName($table);
        $clone = clone $this;
        $clone->table = $table;

        return $clone;
    }

    /**
     * @inheritDoc
     */
    public function upsert(string $id, array $vector, array $metadata): void
    {
        VectorValidator::validate($vector, $this->dimensions);
        $vectorBlob = $this->vectorToBlob($vector);

        $this->db()->transaction(function () use ($id, $vectorBlob, $metadata): void {
            $this->db()->statement(
                "INSERT OR REPLACE INTO {$this->table} (id, embedding, metadata, content, updated_at, created_at)
                 VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))",
                [
                    $id,
                    $vectorBlob,
                    json_encode($metadata),
                    $metadata['content'] ?? '',
                ]
            );

            // Upsert into the virtual vec table for similarity search
            $vecTable = "{$this->table}_vec";
            $this->db()->statement(
                "INSERT OR REPLACE INTO {$vecTable} (rowid, embedding) VALUES ((SELECT rowid FROM {$this->table} WHERE id = ?), ?)",
                [$id, $vectorBlob]
            );
        });
    }

    /**
     * @inheritDoc
     */
    public function similaritySearch(array $vector, int $limit, float $threshold = 0.0): Collection
    {
        $vectorBlob = $this->vectorToBlob($vector);
        $vecTable = "{$this->table}_vec";

        $results = $this->db()->select(
            "SELECT d.id, d.metadata, d.content, v.distance
             FROM {$vecTable} v
             JOIN {$this->table} d ON d.rowid = v.rowid
             WHERE v.embedding MATCH ?
                AND k = ?",
            [$vectorBlob, $limit]
        );

        return collect($results)
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'score' => 1.0 - (float) $row->distance,
                'metadata' => (array) (json_decode((string) $row->metadata, true) ?? []),
                'content' => (string) ($row->content ?? ''),
            ])
            ->filter(fn ($row): bool => $row['score'] >= $threshold)
            ->values();
    }

    /**
     * @inheritDoc
     */
    public function hybridSearch(string $query, array $vector, float $semanticWeight, float $fulltextWeight, int $limit): Collection
    {
        RagLogger::warning('SqliteVecStore: falling back to pure semantic search — hybrid not supported', [
            'table' => $this->table,
        ]);

        return $this->similaritySearch($vector, $limit);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $id): void
    {
        $this->db()->transaction(function () use ($id): void {
            $vecTable = "{$this->table}_vec";

            // Delete from vec table first (needs rowid)
            $this->db()->statement(
                "DELETE FROM {$vecTable} WHERE rowid = (SELECT rowid FROM {$this->table} WHERE id = ?)",
                [$id]
            );

            $this->db()->table($this->table)->where('id', $id)->delete();
        });
    }

    /**
     * @inheritDoc
     */
    public function flush(string $collection): void
    {
        $this->validateTableName($collection);

        $this->db()->transaction(function () use ($collection): void {
            $vecTable = "{$collection}_vec";
            $this->db()->statement("DELETE FROM {$vecTable}");
            $this->db()->table($collection)->truncate();
        });
    }

    /**
     * @inheritDoc
     */
    public function supportsFullTextSearch(): bool
    {
        return false;
    }

    /**
     * Convert a float array to sqlite-vec binary blob format.
     *
     * @param  array<int, float>  $vector
     */
    protected function vectorToBlob(array $vector): string
    {
        return pack('f*', ...$vector);
    }

    /**
     * Validate that a table name is safe for interpolation into SQL.
     *
     * @throws \InvalidArgumentException
     */
    protected function validateTableName(string $table): void
    {
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.]*\z/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
    }

    /**
     * Get the database connection.
     */
    protected function db(): \Illuminate\Database\Connection
    {
        return DB::connection($this->connection);
    }
}
