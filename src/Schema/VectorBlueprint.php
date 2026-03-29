<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

class VectorBlueprint
{
    /**
     * Register Blueprint macros for vector columns and indexes.
     */
    public static function register(): void
    {
        Blueprint::macro('vector', function (string $column, int $dimensions = 1536): ColumnDefinition {
            /** @var Blueprint $this */
            $driver = $this->getConnection()?->getDriverName() ?? 'pgsql';

            if ($driver === 'sqlite') {
                // sqlite-vec uses BLOB for vector storage
                return $this->binary($column);
            }

            // pgvector uses the vector type
            return $this->addColumn('vector', $column, ['dimensions' => $dimensions]);
        });

        Blueprint::macro('vectorIndex', function (string $column, string $method = 'hnsw', string $distance = 'cosine'): void {
            /** @var Blueprint $this */
            $table = $this->getTable();
            $driver = $this->getConnection()?->getDriverName() ?? 'pgsql';

            if ($driver === 'sqlite') {
                // sqlite-vec uses virtual tables for indexing — handled at migration level
                return;
            }

            $distanceOp = match ($distance) {
                'cosine' => 'vector_cosine_ops',
                'l2', 'euclidean' => 'vector_l2_ops',
                'inner_product', 'ip' => 'vector_ip_ops',
                default => 'vector_cosine_ops',
            };

            $indexName = "{$table}_{$column}_{$method}_idx";

            $this->getConnection()->statement(
                "CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} USING {$method} ({$column} {$distanceOp})"
            );
        });

        Blueprint::macro('fulltextIndex', function (string $column, string $language = 'english'): void {
            /** @var Blueprint $this */
            $table = $this->getTable();
            $driver = $this->getConnection()?->getDriverName() ?? 'pgsql';

            if ($driver === 'sqlite') {
                return;
            }

            $indexName = "{$table}_{$column}_fulltext_idx";

            $this->getConnection()->statement(
                "CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} USING gin (to_tsvector('{$language}', {$column}))"
            );
        });
    }
}
