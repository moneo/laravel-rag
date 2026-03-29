<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Tests\Contract;

use Illuminate\Support\Facades\DB;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;
use Moneo\LaravelRag\VectorStores\PgvectorStore;

/**
 * @group pgvector
 * @group contract
 */
class PgvectorStoreContractTest extends VectorStoreContractTest
{
    protected function createStore(): VectorStoreContract
    {
        return new PgvectorStore(
            connection: 'pgsql',
            dimensions: (int) config('rag.embedding.dimensions', 3),
        );
    }

    protected function setUpStoreSchema(): void
    {
        // Check if PG is reachable
        try {
            DB::connection('pgsql')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('PostgreSQL not available — set DB_PG_* env vars or use Docker');

            return;
        }

        // Clean + create schema
        DB::connection('pgsql')->statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS documents');
        DB::connection('pgsql')->statement('
            CREATE TABLE documents (
                id TEXT PRIMARY KEY,
                embedding vector(3),
                metadata JSONB,
                content TEXT,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rag.embedding.dimensions', 3);

        // pgvector connection config
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_PG_HOST', '127.0.0.1'),
            'port' => env('DB_PG_PORT', '5433'),
            'database' => env('DB_PG_DATABASE', 'laravel_rag_test'),
            'username' => env('DB_PG_USERNAME', 'test'),
            'password' => env('DB_PG_PASSWORD', 'test'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Schema managed in setUpStoreSchema, not via Laravel migrations
    }
}
