<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Tests\Contract;

use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;
use Moneo\LaravelRag\VectorStores\SqliteVecStore;

/**
 * @group sqlite-vec
 * @group contract
 */
class SqliteVecStoreContractTest extends VectorStoreContractTest
{
    private static string $dbPath = '/tmp/laravel_rag_contract_test.sqlite';

    private static bool $vecAvailable = false;

    protected function createStore(): VectorStoreContract
    {
        return new SqliteVecStore(
            connection: 'testing',
            dimensions: (int) config('rag.embedding.dimensions', 3),
        );
    }

    protected function setUpStoreSchema(): void
    {
        if (! self::$vecAvailable) {
            $this->markTestSkipped('sqlite-vec not loadable');
        }
    }

    protected function defineEnvironment($app): void
    {
        // Check vec availability once
        if (! self::$vecAvailable) {
            try {
                $db = new \SQLite3(':memory:');
                $db->enableExceptions(true);
                $db->loadExtension('vec0.so');
                $db->close();
                self::$vecAvailable = true;
            } catch (\Throwable) {
                self::$vecAvailable = false;
            }
        }

        // Use file-based SQLite
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => self::$dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('rag.vector_store', 'sqlite-vec');
        $app['config']->set('rag.stores.sqlite-vec.connection', 'testing');
        $app['config']->set('rag.embedding.dimensions', 3);
        $app['config']->set('rag.embedding.cache', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        if (! self::$vecAvailable) {
            return;
        }

        // Create DB file with vec0 tables via SQLite3 class
        @unlink(self::$dbPath);
        $db = new \SQLite3(self::$dbPath);
        $db->enableExceptions(true);
        $db->loadExtension('vec0.so');
        $db->exec('CREATE TABLE documents (id TEXT PRIMARY KEY, embedding BLOB, metadata TEXT, content TEXT, created_at TEXT, updated_at TEXT)');
        $db->exec('CREATE VIRTUAL TABLE documents_vec USING vec0(embedding float[3])');
        $db->close();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(self::$dbPath);
    }
}
