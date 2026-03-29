<?php

declare(strict_types=1);

use Moneo\LaravelRag\Evals\Metrics\ContextRecallMetric;
use Moneo\LaravelRag\Evals\Metrics\FaithfulnessMetric;
use Moneo\LaravelRag\Evals\Metrics\RelevancyMetric;
use Moneo\LaravelRag\Evals\RagEval;
use Moneo\LaravelRag\Pipeline\RagPipeline;
use Moneo\LaravelRag\Pipeline\RagResult;
use Moneo\LaravelRag\Support\PrismRetryHandler;

test('FaithfulnessMetric evaluates via PrismRetryHandler', function () {
    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('generate')->once()->andReturn('0.85');
    app()->instance(PrismRetryHandler::class, $prism);

    $metric = new FaithfulnessMetric;
    $score = $metric->evaluate('What is X?', 'X is Y.', 'X is Y.', 'Context about X.');

    expect($score)->toBe(0.85);
});

test('RelevancyMetric evaluates via PrismRetryHandler', function () {
    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('generate')->once()->andReturn('0.92');
    app()->instance(PrismRetryHandler::class, $prism);

    $metric = new RelevancyMetric;
    $score = $metric->evaluate('What is X?', 'X is Y.', 'X is Y.', 'Context.');

    expect($score)->toBe(0.92);
});

test('ContextRecallMetric evaluates via PrismRetryHandler', function () {
    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('generate')->once()->andReturn('0.78');
    app()->instance(PrismRetryHandler::class, $prism);

    $metric = new ContextRecallMetric;
    $score = $metric->evaluate('Q?', 'A.', 'Expected.', 'Context.');

    expect($score)->toBe(0.78);
});

test('metric parseScore handles LLM text with number', function () {
    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('generate')->andReturn("Score: 0.75\nThe answer is mostly relevant.");
    app()->instance(PrismRetryHandler::class, $prism);

    $metric = new FaithfulnessMetric;
    $score = $metric->evaluate('Q?', 'A.', 'E.', 'C.');

    expect($score)->toBe(0.75);
});

test('RagEval run executes pipeline and metrics', function () {
    $ragResult = new RagResult(
        answer: 'Generated answer.',
        chunks: collect([['id' => '1', 'score' => 0.9, 'metadata' => [], 'content' => 'Context chunk.']]),
        question: 'Q?',
        retrievalTimeMs: 10,
        generationTimeMs: 20,
    );

    $pipeline = Mockery::mock(RagPipeline::class);
    $pipeline->shouldReceive('ask')->andReturn($ragResult);

    // Mock all 3 metrics via PrismRetryHandler
    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('generate')->andReturn('0.90');
    app()->instance(PrismRetryHandler::class, $prism);

    $report = RagEval::suite()
        ->using($pipeline)
        ->add('What is RAG?', 'Retrieval Augmented Generation')
        ->run();

    expect($report->count())->toBe(1)
        ->and($report->passes(0.5))->toBeTrue()
        ->and($report->averageScores)->toHaveKeys(['faithfulness', 'relevancy', 'context_recall']);
});
