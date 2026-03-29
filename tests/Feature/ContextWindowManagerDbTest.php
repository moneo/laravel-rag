<?php

declare(strict_types=1);

use Moneo\LaravelRag\Memory\ContextWindowManager;
use Moneo\LaravelRag\Memory\RagThread;

test('buildContext returns empty for thread with no messages', function () {
    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);
    $manager = new ContextWindowManager;

    expect($manager->buildContext($thread))->toBe('');
});

test('buildContext returns formatted messages within budget', function () {
    config(['rag.memory.max_tokens' => 4000, 'rag.memory.summary_threshold' => 0.8]);

    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);
    $thread->addMessage('user', 'What is Laravel?');
    $thread->addMessage('assistant', 'Laravel is a PHP framework.');

    $manager = new ContextWindowManager;
    $context = $manager->buildContext($thread);

    expect($context)->toContain('User: What is Laravel?')
        ->and($context)->toContain('Assistant: Laravel is a PHP framework.');
});

test('buildContext includes all messages when within token budget', function () {
    config(['rag.memory.max_tokens' => 10000, 'rag.memory.summary_threshold' => 0.8]);

    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);

    for ($i = 1; $i <= 5; $i++) {
        $thread->addMessage('user', "Question {$i}");
        $thread->addMessage('assistant', "Answer {$i}");
    }

    $manager = new ContextWindowManager;
    $context = $manager->buildContext($thread);

    for ($i = 1; $i <= 5; $i++) {
        expect($context)->toContain("Question {$i}")
            ->and($context)->toContain("Answer {$i}");
    }
});
