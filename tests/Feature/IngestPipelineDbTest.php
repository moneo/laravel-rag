<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Moneo\LaravelRag\Cache\EmbeddingCache;
use Moneo\LaravelRag\Chunking\ChunkingFactory;
use Moneo\LaravelRag\Pipeline\IngestPipeline;
use Moneo\LaravelRag\Support\PrismRetryHandler;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

test('run stores chunks via vector store', function () {
    $store = Mockery::mock(VectorStoreContract::class);
    $store->shouldReceive('table')->andReturnSelf();
    $store->shouldReceive('upsert')->atLeast()->once();

    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $pipeline = new IngestPipeline(
        vectorStore: $store,
        chunkingFactory: new ChunkingFactory,
        embeddingCache: new EmbeddingCache(enabled: false),
        prismRetryHandler: $prism,
    );

    $ids = $pipeline->text(str_repeat('Test content. ', 100))
        ->chunk(strategy: 'character', size: 200, overlap: 0)
        ->run();

    expect($ids)->toBeArray()->not->toBeEmpty();
});

test('run with cache stores embeddings in DB cache', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => true]);

    $store = Mockery::mock(VectorStoreContract::class);
    $store->shouldReceive('table')->andReturnSelf();
    $store->shouldReceive('upsert');

    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $pipeline = new IngestPipeline(
        vectorStore: $store,
        chunkingFactory: new ChunkingFactory,
        embeddingCache: new EmbeddingCache(enabled: true),
        prismRetryHandler: $prism,
    );

    $pipeline->text('Short text for caching test.')
        ->chunk(strategy: 'character', size: 5000, overlap: 0)
        ->run();

    expect(DB::table('rag_embedding_cache')->count())->toBeGreaterThan(0);
});

test('run returns empty for empty content', function () {
    $store = Mockery::mock(VectorStoreContract::class);
    $store->shouldReceive('table')->andReturnSelf();
    $prism = Mockery::mock(PrismRetryHandler::class);

    $pipeline = new IngestPipeline(
        vectorStore: $store,
        chunkingFactory: new ChunkingFactory,
        embeddingCache: new EmbeddingCache(enabled: false),
        prismRetryHandler: $prism,
    );

    $ids = $pipeline->text('')->run();

    expect($ids)->toBeEmpty();
});

test('withMetadata attaches metadata to chunks', function () {
    $capturedMetadata = [];

    $store = Mockery::mock(VectorStoreContract::class);
    $store->shouldReceive('table')->andReturnSelf();
    $store->shouldReceive('upsert')->andReturnUsing(function ($id, $vector, $metadata) use (&$capturedMetadata): void {
        $capturedMetadata[] = $metadata;
    });

    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

    $pipeline = new IngestPipeline(
        vectorStore: $store,
        chunkingFactory: new ChunkingFactory,
        embeddingCache: new EmbeddingCache(enabled: false),
        prismRetryHandler: $prism,
    );

    $pipeline->text('Some content here.')
        ->withMetadata(['category' => 'tech', 'lang' => 'en'])
        ->chunk(strategy: 'character', size: 5000, overlap: 0)
        ->run();

    expect($capturedMetadata[0]['category'])->toBe('tech')
        ->and($capturedMetadata[0]['lang'])->toBe('en');
});
