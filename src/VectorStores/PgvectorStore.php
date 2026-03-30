<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\VectorStores;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Moneo\LaravelRag\Exceptions\DeadlockException;
use Moneo\LaravelRag\Security\VectorValidator;
use Moneo\LaravelRag\Support\RagLogger;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

class PgvectorStore implements VectorStoreContract
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

        $this->withDeadlockRetry(function () use ($id, $vector, $metadata): void {
            $vectorString = $this->vectorToString($vector);

            $this->db()->statement(
                "INSERT INTO {$this->table} (id, embedding, metadata, content, updated_at, created_at)
                 VALUES (?, ?::vector, ?, ?, NOW(), NOW())
                 ON CONFLICT (id) DO UPDATE SET
                    embedding = EXCLUDED.embedding,
                    metadata = EXCLUDED.metadata,
                    content = EXCLUDED.content,
                    updated_at = NOW()",
                [
                    $id,
                    $vectorString,
                    json_encode($metadata),
                    $metadata['content'] ?? '',
                ]
            );
        });
    }

    /**
     * @inheritDoc
     */
    public function similaritySearch(array $vector, int $limit, float $threshold = 0.0): Collection
    {
        $vectorString = $this->vectorToString($vector);

        $results = $this->db()->select(
            "SELECT id, metadata, content,
                    1 - (embedding <=> ?::vector) as score
             FROM {$this->table}
             WHERE 1 - (embedding <=> ?::vector) >= ?
             ORDER BY embedding <=> ?::vector
             LIMIT ?",
            [$vectorString, $vectorString, $threshold, $vectorString, $limit]
        );

        return collect($results)->map(fn ($row): array => [
            'id' => (string) $row->id,
            'score' => (float) $row->score,
            'metadata' => (array) (json_decode((string) $row->metadata, true) ?? []),
            'content' => (string) ($row->content ?? ''),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function hybridSearch(string $query, array $vector, float $semanticWeight, float $fulltextWeight, int $limit): Collection
    {
        $vectorString = $this->vectorToString($vector);
        $tsQuery = $this->toTsQuery($query);

        $results = $this->db()->select(
            "WITH semantic AS (
                SELECT id, metadata, content,
                       1 - (embedding <=> ?::vector) as score
                FROM {$this->table}
                ORDER BY embedding <=> ?::vector
                LIMIT ?
            ),
            fulltext AS (
                SELECT id, metadata, content,
                       ts_rank_cd(to_tsvector('english', content), to_tsquery('english', ?)) as score
                FROM {$this->table}
                WHERE to_tsvector('english', content) @@ to_tsquery('english', ?)
                ORDER BY score DESC
                LIMIT ?
            )
            SELECT COALESCE(s.id, f.id) as id,
                   COALESCE(s.metadata, f.metadata) as metadata,
                   COALESCE(s.content, f.content) as content,
                   (COALESCE(s.score, 0) * ? + COALESCE(f.score, 0) * ?) as score
            FROM semantic s
            FULL OUTER JOIN fulltext f ON s.id = f.id
            ORDER BY score DESC
            LIMIT ?",
            [
                $vectorString, $vectorString, $limit * 2,
                $tsQuery, $tsQuery, $limit * 2,
                $semanticWeight, $fulltextWeight,
                $limit,
            ]
        );

        return collect($results)->map(fn ($row): array => [
            'id' => (string) $row->id,
            'score' => (float) $row->score,
            'metadata' => (array) (json_decode((string) $row->metadata, true) ?? []),
            'content' => (string) ($row->content ?? ''),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $id): void
    {
        $this->db()->table($this->table)->where('id', $id)->delete();
    }

    /**
     * @inheritDoc
     */
    public function flush(string $collection): void
    {
        $this->db()->table($collection)->truncate();
    }

    /**
     * @inheritDoc
     */
    public function updateEmbedding(string $id, array $vector, array $metadata = []): void
    {
        VectorValidator::validate($vector, $this->dimensions);
        $vectorString = $this->vectorToString($vector);

        $this->withDeadlockRetry(function () use ($id, $vectorString, $metadata): void {
            $updates = ['embedding = ?::vector'];
            $bindings = [$vectorString];

            if ($metadata !== []) {
                $updates[] = 'metadata = ?';
                $bindings[] = json_encode($metadata);
            }

            if (isset($metadata['content'])) {
                $updates[] = 'content = ?';
                $bindings[] = $metadata['content'];
            }

            $updates[] = 'updated_at = NOW()';
            $bindings[] = $id;

            $this->db()->statement(
                "UPDATE {$this->table} SET ".implode(', ', $updates).' WHERE id = ?',
                $bindings
            );
        });
    }

    /**
     * @inheritDoc
     */
    public function supportsFullTextSearch(): bool
    {
        return true;
    }

    /**
     * Convert a float array to pgvector string format.
     *
     * @param  array<int, float>  $vector
     */
    protected function vectorToString(array $vector): string
    {
        return '['.implode(',', $vector).']';
    }

    /**
     * Convert a natural language query to a tsquery string.
     */
    protected function toTsQuery(string $query): string
    {
        $words = preg_split('/\s+/', trim($query));

        return implode(' & ', array_filter($words));
    }

    /**
     * Execute an operation with deadlock retry logic.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @param  int  $maxRetries  Maximum retry attempts
     * @return T
     *
     * @throws DeadlockException
     */
    protected function withDeadlockRetry(callable $operation, int $maxRetries = 3): mixed
    {
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $operation();
            } catch (QueryException $e) {
                // PostgreSQL deadlock error code: 40P01
                if ($e->getCode() === '40P01' && $attempt < $maxRetries) {
                    RagLogger::warning('pgvector deadlock detected, retrying', [
                        'attempt' => $attempt + 1,
                        'table' => $this->table,
                    ]);
                    usleep(100_000 * (2 ** $attempt)); // 100ms, 200ms, 400ms

                    continue;
                }

                if ($e->getCode() === '40P01') {
                    throw new DeadlockException(
                        "Deadlock detected after {$maxRetries} retries on table {$this->table}: {$e->getMessage()}",
                        0,
                        $e,
                    );
                }

                throw $e;
            }
        }

        // Unreachable, but satisfies static analysis
        throw new DeadlockException("Deadlock retry exhausted on table {$this->table}");
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
