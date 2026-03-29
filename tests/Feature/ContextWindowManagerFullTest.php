<?php

declare(strict_types=1);

use Moneo\LaravelRag\Memory\ContextWindowManager;
use Moneo\LaravelRag\Memory\RagThread;
use Moneo\LaravelRag\Support\PrismRetryHandler;

test('buildContext triggers summarisation when over token budget', function () {
    config([
        'rag.memory.max_tokens' => 50,
        'rag.memory.summary_threshold' => 0.8,
    ]);

    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);

    // Add enough messages to exceed budget (50 * 0.8 = 40 token threshold)
    $thread->addMessage('user', str_repeat('question ', 50));       // ~50 tokens
    $thread->addMessage('assistant', str_repeat('answer ', 50));     // ~50 tokens
    $thread->addMessage('user', 'Latest question?');                  // ~5 tokens

    // Mock PrismRetryHandler for summarisation
    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('generate')
        ->once()
        ->andReturn('Summary of earlier conversation about questions and answers.');
    app()->instance(PrismRetryHandler::class, $prism);

    $manager = new ContextWindowManager;
    $context = $manager->buildContext($thread);

    expect($context)->toContain('Summary of earlier conversation')
        ->and($context)->toContain('Latest question?');
});

test('buildContext does not summarise when within budget', function () {
    config([
        'rag.memory.max_tokens' => 10000,
        'rag.memory.summary_threshold' => 0.8,
    ]);

    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);
    $thread->addMessage('user', 'Short question');
    $thread->addMessage('assistant', 'Short answer');

    // PrismRetryHandler should NOT be called (no summarisation needed)
    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldNotReceive('generate');
    app()->instance(PrismRetryHandler::class, $prism);

    $manager = new ContextWindowManager;
    $context = $manager->buildContext($thread);

    expect($context)->toContain('User: Short question')
        ->and($context)->toContain('Assistant: Short answer')
        ->and($context)->not->toContain('Summary');
});
