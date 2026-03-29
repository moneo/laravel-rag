<?php

declare(strict_types=1);

namespace Moneo\LaravelRag;

use Illuminate\Support\ServiceProvider;
use Moneo\LaravelRag\Cache\EmbeddingCache;
use Moneo\LaravelRag\Chunking\ChunkingFactory;
use Moneo\LaravelRag\Evals\RagEval;
use Moneo\LaravelRag\Pipeline\IngestPipeline;
use Moneo\LaravelRag\Pipeline\RagPipeline;
use Moneo\LaravelRag\Schema\VectorBlueprint;
use Moneo\LaravelRag\Search\HybridSearch;
use Moneo\LaravelRag\Search\Reranker;
use Moneo\LaravelRag\Support\PrismRetryHandler;
use Moneo\LaravelRag\Support\RagLogger;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;
use Moneo\LaravelRag\VectorStores\PgvectorStore;
use Moneo\LaravelRag\VectorStores\SqliteVecStore;

class RagServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rag.php', 'rag');

        $this->registerSupport();
        $this->registerVectorStore();
        $this->registerEmbeddingCache();
        $this->registerChunkingFactory();
        $this->registerPipelines();
        $this->registerSearch();
        $this->registerEvals();
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->validateConfig();
        $this->publishConfig();
        $this->publishMigrations();
        $this->registerCommands();
        $this->registerBlueprintMacros();
        $this->registerDevTools();
        $this->registerLivewire();
        $this->registerFilament();
    }

    protected function validateConfig(): void
    {
        $dimensions = (int) config('rag.embedding.dimensions', 1536);
        $chunkSize = (int) config('rag.ingest.chunk_size', 500);
        $chunkOverlap = (int) config('rag.ingest.chunk_overlap', 50);
        $defaultLimit = (int) config('rag.search.default_limit', 5);

        if ($dimensions <= 0) {
            throw new \InvalidArgumentException("rag.embedding.dimensions must be > 0, got {$dimensions}");
        }

        if ($chunkSize <= 0) {
            throw new \InvalidArgumentException("rag.ingest.chunk_size must be > 0, got {$chunkSize}");
        }

        if ($chunkOverlap < 0) {
            throw new \InvalidArgumentException("rag.ingest.chunk_overlap must be >= 0, got {$chunkOverlap}");
        }

        if ($chunkOverlap >= $chunkSize) {
            throw new \InvalidArgumentException("rag.ingest.chunk_overlap ({$chunkOverlap}) must be < chunk_size ({$chunkSize})");
        }

        if ($defaultLimit <= 0) {
            throw new \InvalidArgumentException("rag.search.default_limit must be > 0, got {$defaultLimit}");
        }
    }

    protected function registerSupport(): void
    {
        $this->app->singleton(PrismRetryHandler::class);
        $this->app->singleton(RagLogger::class);
    }

    protected function registerVectorStore(): void
    {
        $this->app->singleton(VectorStoreContract::class, function (array $app): \Moneo\LaravelRag\VectorStores\PgvectorStore|\Moneo\LaravelRag\VectorStores\SqliteVecStore {
            $driver = $app['config']['rag.vector_store'];
            $storeConfig = $app['config']["rag.stores.{$driver}"];

            return match ($storeConfig['driver']) {
                'pgvector' => new PgvectorStore(
                    connection: $storeConfig['connection'],
                    dimensions: (int) $app['config']['rag.embedding.dimensions'],
                ),
                'sqlite-vec' => new SqliteVecStore(
                    connection: $storeConfig['connection'],
                    dimensions: (int) $app['config']['rag.embedding.dimensions'],
                ),
                default => throw new \InvalidArgumentException("Unsupported vector store driver: {$storeConfig['driver']}"),
            };
        });
    }

    protected function registerEmbeddingCache(): void
    {
        $this->app->singleton(EmbeddingCache::class, fn(array $app): \Moneo\LaravelRag\Cache\EmbeddingCache => new EmbeddingCache(
            enabled: (bool) $app['config']['rag.embedding.cache'],
        ));
    }

    protected function registerChunkingFactory(): void
    {
        $this->app->singleton(ChunkingFactory::class);
    }

    protected function registerPipelines(): void
    {
        $this->app->bind('rag.pipeline', fn($app): \Moneo\LaravelRag\Pipeline\RagPipeline => new RagPipeline(
            vectorStore: $app->make(VectorStoreContract::class),
            embeddingCache: $app->make(EmbeddingCache::class),
            hybridSearch: $app->make(HybridSearch::class),
            reranker: $app->make(Reranker::class),
            prismRetryHandler: $app->make(PrismRetryHandler::class),
        ));

        $this->app->bind('rag.ingest', fn($app): \Moneo\LaravelRag\Pipeline\IngestPipeline => new IngestPipeline(
            vectorStore: $app->make(VectorStoreContract::class),
            chunkingFactory: $app->make(ChunkingFactory::class),
            embeddingCache: $app->make(EmbeddingCache::class),
            prismRetryHandler: $app->make(PrismRetryHandler::class),
        ));
    }

    protected function registerSearch(): void
    {
        $this->app->singleton(HybridSearch::class, fn(array $app): \Moneo\LaravelRag\Search\HybridSearch => new HybridSearch(
            vectorStore: $app->make(VectorStoreContract::class),
            rrfK: (int) $app['config']['rag.search.rrf_k'],
        ));

        $this->app->singleton(Reranker::class, fn(array $app): \Moneo\LaravelRag\Search\Reranker => new Reranker(
            enabled: (bool) $app['config']['rag.reranker.enabled'],
            topK: (int) $app['config']['rag.reranker.top_k'],
        ));
    }

    protected function registerEvals(): void
    {
        $this->app->bind('rag.eval', fn(): \Moneo\LaravelRag\Evals\RagEval => new RagEval());
    }

    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/rag.php' => config_path('rag.php'),
        ], 'rag-config');
    }

    protected function publishMigrations(): void
    {
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'rag-migrations');
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\VectorizeIndexCommand::class,
                Commands\RagTestCommand::class,
                Commands\RagEstimateCommand::class,
                Commands\RagEvalCommand::class,
                Commands\McpServeCommand::class,
            ]);
        }
    }

    protected function registerBlueprintMacros(): void
    {
        VectorBlueprint::register();
    }

    protected function registerDevTools(): void
    {
        if (class_exists(\Barryvdh\Debugbar\LaravelDebugbar::class)) {
            $this->app->booted(function ($app): void {
                $debugbar = $app->make(\Barryvdh\Debugbar\LaravelDebugbar::class);
                $debugbar->addCollector($app->make(DevTools\DebugbarCollector::class));
            });
        }

        if (class_exists(\Laravel\Telescope\Telescope::class)) {
            $this->app->register(DevTools\TelescopeWatcher::class);
        }
    }

    protected function registerLivewire(): void
    {
        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component('rag-chat', Livewire\RagChat::class);
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'rag');
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/rag'),
            ], 'rag-views');
        }
    }

    protected function registerFilament(): void
    {
        // Filament auto-discovers plugins via the RagPlugin class
    }
}
