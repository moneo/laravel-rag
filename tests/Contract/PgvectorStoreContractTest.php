<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Tests\Contract;

use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;
use Moneo\LaravelRag\VectorStores\PgvectorStore;

/**
 * Contract tests for PgvectorStore.
 *
 * These tests verify that PgvectorStore satisfies the VectorStoreContract.
 * Requires a PostgreSQL instance with pgvector extension.
 *
 * @group pgvector
 * @group contract
 * @requires-postgres
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
        $this->markTestSkipped('pgvector contract tests require PostgreSQL with pgvector — run in CI with --group=contract');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rag.embedding.dimensions', 3);
    }
}
