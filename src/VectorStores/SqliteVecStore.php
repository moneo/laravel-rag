<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\VectorStores;

use Illuminate\Support\Collection;
use Moneo\LaravelRag\Exceptions\VectorStoreException;
use Moneo\LaravelRag\Security\VectorValidator;
use Moneo\LaravelRag\Support\RagLogger;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

/**
 * SQLite-vec vector store driver.
 *
 * Uses PHP's SQLite3 class (not PDO) because sqlite-vec requires
 * SQLite3::loadExtension() to load the vec0 module at runtime.
 * PDO SQLite does not support load_extension().
 */
class SqliteVecStore implements VectorStoreContract
{
    protected string $table = 'documents';

    protected ?\SQLite3 $sqlite = null;

    /**
     * @param  string  $database  Path to the SQLite database file, or ":memory:"
     * @param  int  $dimensions  The vector dimensions
     * @param  string|null  $extensionPath  Override vec0 extension path (null = auto from ini)
     */
    public function __construct(
        protected readonly string $database,
        protected readonly int $dimensions,
        protected readonly ?string $extensionPath = null,
    ) {}

    /**
     * @inheritDoc
     */
    public function table(string $table): static
    {
        $this->validateTableName($table);
        $clone = clone $this;
        $clone->table = $table;
        $clone->sqlite = null; // Force new connection for clone

        return $clone;
    }

    /**
     * @inheritDoc
     */
    public function upsert(string $id, array $vector, array $metadata): void
    {
        VectorValidator::validate($vector, $this->dimensions);
        $vectorBlob = $this->vectorToBlob($vector);

        $db = $this->db();

        $db->exec('BEGIN TRANSACTION');

        try {
            $stmt = $db->prepare(
                "INSERT OR REPLACE INTO {$this->table} (id, embedding, metadata, content, updated_at, created_at)
                 VALUES (:id, :embedding, :metadata, :content, datetime('now'), datetime('now'))"
            );
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt->bindValue(':embedding', $vectorBlob, SQLITE3_BLOB);
            $stmt->bindValue(':metadata', json_encode($metadata), SQLITE3_TEXT);
            $stmt->bindValue(':content', $metadata['content'] ?? '', SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Upsert into the virtual vec table
            $vecTable = "{$this->table}_vec";
            $stmt2 = $db->prepare(
                "INSERT OR REPLACE INTO {$vecTable} (rowid, embedding)
                 VALUES ((SELECT rowid FROM {$this->table} WHERE id = :id), :embedding)"
            );
            $stmt2->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt2->bindValue(':embedding', $vectorBlob, SQLITE3_BLOB);
            $stmt2->execute();
            $stmt2->close();

            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');

            throw new VectorStoreException("SqliteVecStore upsert failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function similaritySearch(array $vector, int $limit, float $threshold = 0.0): Collection
    {
        $vectorBlob = $this->vectorToBlob($vector);
        $vecTable = "{$this->table}_vec";
        $db = $this->db();

        $stmt = $db->prepare(
            "SELECT d.id, d.metadata, d.content, v.distance
             FROM {$vecTable} v
             JOIN {$this->table} d ON d.rowid = v.rowid
             WHERE v.embedding MATCH :embedding
                AND k = :limit"
        );
        $stmt->bindValue(':embedding', $vectorBlob, SQLITE3_BLOB);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $rows = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $stmt->close();

        return collect($rows)
            ->map(fn (array $row): array => [
                'id' => (string) $row['id'],
                'score' => 1.0 - (float) $row['distance'],
                'metadata' => (array) (json_decode((string) $row['metadata'], true) ?? []),
                'content' => (string) ($row['content'] ?? ''),
            ])
            ->filter(fn (array $row): bool => $row['score'] >= $threshold)
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
        $db = $this->db();
        $db->exec('BEGIN TRANSACTION');

        try {
            $vecTable = "{$this->table}_vec";

            $stmt = $db->prepare("DELETE FROM {$vecTable} WHERE rowid = (SELECT rowid FROM {$this->table} WHERE id = :id)");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            $stmt2 = $db->prepare("DELETE FROM {$this->table} WHERE id = :id");
            $stmt2->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt2->execute();
            $stmt2->close();

            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');

            throw new VectorStoreException("SqliteVecStore delete failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function flush(string $collection): void
    {
        $this->validateTableName($collection);

        $db = $this->db();
        $db->exec('BEGIN TRANSACTION');

        try {
            $vecTable = "{$collection}_vec";
            $db->exec("DELETE FROM {$vecTable}");
            $db->exec("DELETE FROM {$collection}");
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');

            throw new VectorStoreException("SqliteVecStore flush failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function updateEmbedding(string $id, array $vector, array $metadata = []): void
    {
        VectorValidator::validate($vector, $this->dimensions);
        $vectorBlob = $this->vectorToBlob($vector);

        $db = $this->db();
        $db->exec('BEGIN TRANSACTION');

        try {
            // Update main table
            $stmt = $db->prepare("UPDATE {$this->table} SET embedding = :embedding, updated_at = datetime('now') WHERE id = :id");
            $stmt->bindValue(':embedding', $vectorBlob, SQLITE3_BLOB);
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Update vec table
            $vecTable = "{$this->table}_vec";
            $stmt2 = $db->prepare("INSERT OR REPLACE INTO {$vecTable} (rowid, embedding) VALUES ((SELECT rowid FROM {$this->table} WHERE id = :id), :embedding)");
            $stmt2->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt2->bindValue(':embedding', $vectorBlob, SQLITE3_BLOB);
            $stmt2->execute();
            $stmt2->close();

            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');

            throw new VectorStoreException("SqliteVecStore updateEmbedding failed: {$e->getMessage()}", 0, $e);
        }
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
     * Get or create the SQLite3 connection with vec0 extension loaded.
     *
     * @throws VectorStoreException
     */
    protected function db(): \SQLite3
    {
        if ($this->sqlite instanceof \SQLite3) {
            return $this->sqlite;
        }

        try {
            $this->sqlite = new \SQLite3($this->database);
            $this->sqlite->enableExceptions(true);
            $this->sqlite->busyTimeout(5000);

            // Load sqlite-vec extension
            $extension = $this->extensionPath ?? 'vec0.so';
            $this->sqlite->loadExtension($extension);
        } catch (\Throwable $e) {
            $hint = str_contains($e->getMessage(), 'Extensions are disabled')
                ? 'Your PHP binary was compiled without SQLite extension loading support. '
                  .'This is common on macOS (Herd, Homebrew). Options: '
                  .'(1) Use Docker/Laravel Sail, '
                  .'(2) Switch to pgvector: RAG_VECTOR_STORE=pgvector in .env, '
                  .'(3) Install PHP from ondrej/php PPA (Linux).'
                : 'Ensure sqlite-vec is installed and sqlite3.extension_dir is set in php.ini. '
                  .'Download from: https://github.com/asg017/sqlite-vec/releases';

            throw new VectorStoreException(
                "Failed to initialize SqliteVecStore: {$e->getMessage()}. {$hint}",
                0,
                $e,
            );
        }

        return $this->sqlite;
    }

    public function __destruct()
    {
        if ($this->sqlite instanceof \SQLite3) {
            $this->sqlite->close();
            $this->sqlite = null;
        }
    }
}
