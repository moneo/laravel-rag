<?php

declare(strict_types=1);

use Moneo\LaravelRag\Memory\RagThread;
use Moneo\LaravelRag\Memory\ThreadMessage;

test('creates thread with metadata', function () {
    $thread = RagThread::create([
        'model' => 'App\\Models\\Document',
        'title' => 'Test Thread',
        'metadata' => ['key' => 'value'],
    ]);

    expect($thread->id)->toBeGreaterThan(0)
        ->and($thread->model)->toBe('App\\Models\\Document')
        ->and($thread->title)->toBe('Test Thread')
        ->and($thread->metadata)->toBe(['key' => 'value']);
});

test('addMessage creates thread message', function () {
    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);

    $msg = $thread->addMessage('user', 'Hello, what is RAG?');

    expect($msg)->toBeInstanceOf(ThreadMessage::class)
        ->and($msg->role)->toBe('user')
        ->and($msg->content)->toBe('Hello, what is RAG?')
        ->and($msg->tokens)->toBeGreaterThan(0)
        ->and($msg->thread_id)->toBe($thread->id);
});

test('messages relationship returns ordered messages', function () {
    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);

    $thread->addMessage('user', 'First');
    $thread->addMessage('assistant', 'Second');
    $thread->addMessage('user', 'Third');

    $messages = $thread->messages()->get();

    expect($messages)->toHaveCount(3)
        ->and($messages[0]->content)->toBe('First')
        ->and($messages[1]->content)->toBe('Second')
        ->and($messages[2]->content)->toBe('Third');
});

test('totalTokens sums message tokens', function () {
    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);

    $thread->addMessage('user', str_repeat('a', 400));       // ~100 tokens
    $thread->addMessage('assistant', str_repeat('b', 800));   // ~200 tokens

    expect($thread->totalTokens())->toBeGreaterThan(200);
});

test('cascade delete removes messages', function () {
    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);
    $thread->addMessage('user', 'Will be deleted');
    $thread->addMessage('assistant', 'Also deleted');

    $threadId = $thread->id;
    $thread->delete();

    expect(ThreadMessage::where('thread_id', $threadId)->count())->toBe(0);
});

test('thread message belongs to thread', function () {
    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);
    $msg = $thread->addMessage('user', 'Test');

    expect($msg->thread->id)->toBe($thread->id);
});

test('addMessage with metadata', function () {
    $thread = RagThread::create(['model' => 'App\\Models\\Doc']);

    $msg = $thread->addMessage('user', 'Test', ['source' => 'api']);

    expect($msg->metadata)->toBe(['source' => 'api']);
});
