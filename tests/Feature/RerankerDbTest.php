<?php

declare(strict_types=1);

use Moneo\LaravelRag\Search\Reranker;
use Moneo\LaravelRag\Support\PrismRetryHandler;

test('reranker scores and sorts chunks when enabled', function () {
    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('generate')
        ->andReturn('8.5', '3.0', '9.2'); // scores for 3 chunks
    app()->instance(PrismRetryHandler::class, $prism);

    $reranker = new Reranker(enabled: true, topK: 2);

    $chunks = collect([
        ['id' => 'a', 'score' => 0.9, 'metadata' => [], 'content' => 'Low relevance'],
        ['id' => 'b', 'score' => 0.8, 'metadata' => [], 'content' => 'Very low relevance'],
        ['id' => 'c', 'score' => 0.7, 'metadata' => [], 'content' => 'High relevance'],
    ]);

    $result = $reranker->rerank('test query', $chunks);

    expect($result)->toHaveCount(2)
        ->and($result->first()['id'])->toBe('c');  // highest rerank score (9.2)
});

test('reranker caches scores', function () {
    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('generate')->once()->andReturn('7.0'); // Only called once due to cache
    app()->instance(PrismRetryHandler::class, $prism);

    $reranker = new Reranker(enabled: true, topK: 5);

    $chunks = collect([
        ['id' => 'a', 'score' => 0.9, 'metadata' => [], 'content' => 'Test content'],
    ]);

    // First call
    $reranker->rerank('same query', $chunks);

    // Second call — should use cache
    $result = $reranker->rerank('same query', $chunks);

    expect($result)->toHaveCount(1);
});
