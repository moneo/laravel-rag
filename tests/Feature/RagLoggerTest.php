<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Moneo\LaravelRag\Support\RagLogger;

test('embedding logs info with context', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'rag.embedding.generated'));

    RagLogger::embedding('generated', ['driver' => 'openai', 'model' => 'text-embedding-3-small']);
});

test('cache logs debug with hashed text', function () {
    Log::shouldReceive('debug')
        ->once()
        ->withArgs(function ($msg, $ctx) {
            return str_contains($msg, 'rag.cache.hit')
                && isset($ctx['text_hash'])
                && ! isset($ctx['text']); // raw text must not appear
        });

    RagLogger::cache('hit', ['text' => 'sensitive user query']);
});

test('error logs with exception details', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($msg, $ctx) {
            return str_contains($msg, 'rag.error.pipeline')
                && $ctx['exception'] === 'RuntimeException'
                && $ctx['message'] === 'test error';
        });

    RagLogger::error('pipeline', new \RuntimeException('test error'));
});

test('warning logs with context', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'rag.warning'));

    RagLogger::warning('test warning', ['driver' => 'sqlite-vec']);
});

test('retrieval logs info', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'rag.retrieval.search'));

    RagLogger::retrieval('search', ['limit' => 5, 'threshold' => 0.8]);
});

test('generation logs info', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'rag.generation.complete'));

    RagLogger::generation('complete', ['provider' => 'openai', 'tokens' => 150]);
});

test('ingest logs info', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'rag.ingest.start'));

    RagLogger::ingest('start', ['strategy' => 'character', 'chunk_count' => 10]);
});
