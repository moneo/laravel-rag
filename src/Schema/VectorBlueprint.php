<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;

class VectorBlueprint
{
    /**
     * Register Blueprint macros for vector columns and indexes.
     *
     * These macros add pgvector support to Laravel's Schema Builder:
     * - $table->vector('embedding', 1536) — adds a vector column
     * - $table->vectorIndex('embedding') — creates HNSW cosine index
     * - $table->fulltextIndex('content') — creates GIN fulltext index
     */
    public static function register(): void
    {
        Blueprint::macro('vector', function (string $column, int $dimensions = 1536): ColumnDefinition {
            /** @var Blueprint $this */
            // Detect driver via app('db') — compatible with all Laravel versions
            $driver = self::detectDriver();

            if ($driver === 'sqlite') {
                return $this->binary($column);
            }

            return $this->addColumn('vector', $column, ['dimensions' => $dimensions]);
        });

        Blueprint::macro('vectorIndex', function (string $column, string $method = 'hnsw', string $distance = 'cosine'): void {
            /** @var Blueprint $this */
            $driver = self::detectDriver();

            if ($driver === 'sqlite') {
                return;
            }

            $table = $this->getTable();

            $distanceOp = match ($distance) {
                'cosine' => 'vector_cosine_ops',
                'l2', 'euclidean' => 'vector_l2_ops',
                'inner_product', 'ip' => 'vector_ip_ops',
                default => 'vector_cosine_ops',
            };

            $indexName = "{$table}_{$column}_{$method}_idx";

            DB::statement(
                "CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} USING {$method} ({$column} {$distanceOp})"
            );
        });

        Blueprint::macro('fulltextIndex', function (string $column, string $language = 'english'): void {
            /** @var Blueprint $this */
            $driver = self::detectDriver();

            if ($driver === 'sqlite') {
                return;
            }

            $table = $this->getTable();
            $indexName = "{$table}_{$column}_fulltext_idx";

            DB::statement(
                "CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} USING gin (to_tsvector('{$language}', {$column}))"
            );
        });
    }

    /**
     * Detect the current database driver.
     * Works across all Laravel versions (11, 12, 13+).
     */
    private static function detectDriver(): string
    {
        try {
            return DB::connection()->getDriverName();
        } catch (\Throwable) {
            return 'pgsql';
        }
    }
}
