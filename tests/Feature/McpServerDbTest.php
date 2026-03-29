<?php

declare(strict_types=1);

use Moneo\LaravelRag\Mcp\RagMcpServer;
use Moneo\LaravelRag\Pipeline\RagPipeline;
use Moneo\LaravelRag\Pipeline\RagResult;

test('handleSearch returns chunks from pipeline dryRun', function () {
    $pipeline = Mockery::mock(RagPipeline::class);
    $pipeline->shouldReceive('limit')->andReturn($pipeline);
    $pipeline->shouldReceive('dryRun')->with('test query')->andReturn(collect([
        ['id' => 'c1', 'score' => 0.9, 'metadata' => ['source' => 'doc.md'], 'content' => 'Result text'],
    ]));

    $server = new RagMcpServer;
    $server->addTool('kb', 'Knowledge base', 'App\\Models\\Doc', $pipeline);

    $response = $server->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'kb_search', 'arguments' => ['query' => 'test query', 'limit' => 3]],
    ]);

    expect($response)->toHaveKey('result')
        ->and($response['result']['content'])->toHaveCount(1);

    $decoded = json_decode($response['result']['content'][0]['text'], true);
    expect($decoded['id'])->toBe('c1')
        ->and($decoded['score'])->toBe(0.9);
});

test('handleAsk returns generated answer from pipeline', function () {
    $ragResult = new RagResult(
        answer: 'The answer is 42.',
        chunks: collect([['id' => '1', 'score' => 0.9, 'metadata' => ['source' => 'guide.md'], 'content' => 'Context']]),
        question: 'What?',
        retrievalTimeMs: 50.0,
        generationTimeMs: 100.0,
    );

    $pipeline = Mockery::mock(RagPipeline::class);
    $pipeline->shouldReceive('askWithSources')->with('What is the answer?')->andReturn($ragResult);

    $server = new RagMcpServer;
    $server->addTool('docs', 'Search docs', 'App\\Models\\Doc', $pipeline);

    $response = $server->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'docs_ask', 'arguments' => ['question' => 'What is the answer?']],
    ]);

    expect($response)->toHaveKey('result');
    $decoded = json_decode($response['result']['content'][0]['text'], true);
    expect($decoded['answer'])->toBe('The answer is 42.');
});
