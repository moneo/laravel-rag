<?php

declare(strict_types=1);

use Moneo\LaravelRag\Streaming\RagStream;

test('toStreamedResponse has correct SSE headers', function () {
    $stream = new RagStream(
        question: 'What is RAG?',
        context: 'RAG is retrieval augmented generation.',
        chunks: collect([
            ['id' => '1', 'score' => 0.95, 'metadata' => ['source' => 'docs.md'], 'content' => 'RAG content'],
        ]),
        systemPrompt: 'Be helpful.',
        provider: 'openai',
        model: 'gpt-4o',
    );

    $response = $stream->toStreamedResponse();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('text/event-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no');
});

test('getSources maps chunk metadata to source array', function () {
    $stream = new RagStream(
        question: 'q',
        context: 'c',
        chunks: collect([
            ['id' => '1', 'score' => 0.9, 'metadata' => ['source' => 'a.md'], 'content' => str_repeat('x', 300)],
            ['id' => '2', 'score' => 0.7, 'metadata' => [], 'content' => 'short'],
        ]),
        systemPrompt: null,
        provider: 'openai',
        model: 'gpt-4',
    );

    $ref = new ReflectionClass($stream);
    $method = $ref->getMethod('getSources');
    $method->setAccessible(true);
    $sources = $method->invoke($stream);

    expect($sources)->toHaveCount(2)
        ->and($sources[0]['source'])->toBe('a.md')
        ->and($sources[0]['score'])->toBe(0.9)
        ->and(strlen($sources[0]['preview']))->toBe(200)
        ->and($sources[1]['source'])->toBe('Unknown');
});
