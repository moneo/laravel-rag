<?php

declare(strict_types=1);

use Moneo\LaravelRag\Exceptions\EmbeddingRateLimitException;
use Moneo\LaravelRag\Exceptions\EmbeddingServiceException;
use Moneo\LaravelRag\Exceptions\EmbeddingTimeoutException;
use Moneo\LaravelRag\Exceptions\GenerationException;
use Moneo\LaravelRag\Support\PrismRetryHandler;

test('embed retries on 500 and succeeds on 2nd attempt', function () {
    $handler = Mockery::mock(PrismRetryHandler::class)->makePartial();
    $handler->shouldAllowMockingProtectedMethods();
    $handler->shouldReceive('sleep')->andReturn();

    $calls = 0;
    $ref = new ReflectionClass($handler);
    $method = $ref->getMethod('retry');
    $method->setAccessible(true);

    $result = $method->invoke($handler, function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw new \RuntimeException('Internal server error', 500);
        }

        return [0.1, 0.2, 0.3];
    }, 'embedding');

    expect($result)->toBe([0.1, 0.2, 0.3])
        ->and($calls)->toBe(2);
});

test('generate throws GenerationException after max retries', function () {
    $handler = Mockery::mock(PrismRetryHandler::class)->makePartial();
    $handler->shouldAllowMockingProtectedMethods();
    $handler->shouldReceive('sleep')->andReturn();

    $ref = new ReflectionClass($handler);
    $method = $ref->getMethod('retry');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($handler, function (): never {
        throw new \RuntimeException('always fails', 500);
    }, 'generation'))->toThrow(EmbeddingServiceException::class);
});

test('classify preserves original exception as previous', function () {
    $handler = new PrismRetryHandler;
    $ref = new ReflectionClass($handler);
    $method = $ref->getMethod('classify');
    $method->setAccessible(true);

    $original = new \RuntimeException('Rate limit exceeded', 429);
    $classified = $method->invoke($handler, $original, 'embedding');

    expect($classified)->toBeInstanceOf(EmbeddingRateLimitException::class)
        ->and($classified->getPrevious())->toBe($original);
});

test('classify timeout from connection error message', function () {
    $handler = new PrismRetryHandler;
    $ref = new ReflectionClass($handler);
    $method = $ref->getMethod('classify');
    $method->setAccessible(true);

    $original = new \RuntimeException('Connection timed out after 30 seconds');
    $classified = $method->invoke($handler, $original, 'embedding');

    expect($classified)->toBeInstanceOf(EmbeddingTimeoutException::class);
});

test('classify connection reset as retryable', function () {
    $handler = new PrismRetryHandler;
    $ref = new ReflectionClass($handler);
    $method = $ref->getMethod('isRetryable');
    $method->setAccessible(true);

    expect($method->invoke($handler, new \RuntimeException('Connection reset by peer')))->toBeTrue();
});
