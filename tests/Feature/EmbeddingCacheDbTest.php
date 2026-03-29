<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Moneo\LaravelRag\Cache\EmbeddingCache;

test('put stores embedding in database', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => true]);
    $cache = new EmbeddingCache(enabled: true);

    $cache->put('hello world', [0.1, 0.2, 0.3]);

    $row = DB::table('rag_embedding_cache')->first();
    expect($row)->not->toBeNull()
        ->and($row->text_preview)->toBe('hello world');
});

test('get retrieves stored embedding', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => true]);
    $cache = new EmbeddingCache(enabled: true);

    $cache->put('test text', [0.4, 0.5, 0.6]);
    $result = $cache->get('test text');

    expect($result)->toBe([0.4, 0.5, 0.6]);
});

test('get returns null for missing key', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => true]);
    $cache = new EmbeddingCache(enabled: true);

    $result = $cache->get('nonexistent');

    expect($result)->toBeNull()
        ->and($cache->getMisses())->toBe(1);
});

test('put updates existing entry', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => true]);
    $cache = new EmbeddingCache(enabled: true);

    $cache->put('text', [0.1, 0.2]);
    $cache->put('text', [0.3, 0.4]);

    $result = $cache->get('text');
    expect($result)->toBe([0.3, 0.4]);

    $count = DB::table('rag_embedding_cache')->count();
    expect($count)->toBe(1);
});

test('forget removes entry', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => true]);
    $cache = new EmbeddingCache(enabled: true);

    $cache->put('to-delete', [0.1]);
    $cache->forget('to-delete');

    expect($cache->get('to-delete'))->toBeNull();
});

test('flush removes all entries', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => true]);
    $cache = new EmbeddingCache(enabled: true);

    $cache->put('one', [0.1]);
    $cache->put('two', [0.2]);
    $cache->flush();

    expect(DB::table('rag_embedding_cache')->count())->toBe(0);
});

test('tracks hits and misses with real DB', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => true]);
    $cache = new EmbeddingCache(enabled: true);

    $cache->put('exists', [0.1]);
    $cache->get('exists');       // hit
    $cache->get('nope');         // miss
    $cache->get('exists');       // hit

    expect($cache->getHits())->toBe(2)
        ->and($cache->getMisses())->toBe(1)
        ->and($cache->getHitRate())->toBeGreaterThan(0.6);
});

test('corrupted cache entry is auto-evicted', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => true]);
    $cache = new EmbeddingCache(enabled: true);

    // Insert corrupted data directly
    $hash = hash_hmac('sha256', 'corrupted', 'test-key');
    DB::table('rag_embedding_cache')->insert([
        'hash' => $hash,
        'embedding' => '"not an array"',
        'text_preview' => 'corrupted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = $cache->get('corrupted');

    expect($result)->toBeNull()
        ->and(DB::table('rag_embedding_cache')->where('hash', $hash)->count())->toBe(0);
});
