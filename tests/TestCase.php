<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Tests;

use Moneo\LaravelRag\RagServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            RagServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Rag' => \Moneo\LaravelRag\Facades\Rag::class,
            'Ingest' => \Moneo\LaravelRag\Facades\Ingest::class,
            'RagEval' => \Moneo\LaravelRag\Facades\RagEval::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Use in-memory SQLite for all tests
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('rag.vector_store', 'sqlite-vec');
        $app['config']->set('rag.stores.sqlite-vec.connection', 'testing');
        $app['config']->set('rag.embedding.driver', 'openai');
        $app['config']->set('rag.embedding.model', 'text-embedding-3-small');
        $app['config']->set('rag.embedding.dimensions', 1536);
        $app['config']->set('rag.embedding.cache', false);
        $app['config']->set('rag.llm.provider', 'openai');
        $app['config']->set('rag.llm.model', 'gpt-4o');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Create the documents table for vector store tests
        \Illuminate\Support\Facades\Schema::create('documents', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('id')->primary();
            $table->binary('embedding')->nullable();
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
