<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Support;

use Moneo\LaravelRag\Exceptions\DimensionMismatchException;
use Moneo\LaravelRag\Exceptions\EmbeddingRateLimitException;
use Moneo\LaravelRag\Exceptions\EmbeddingResponseException;
use Moneo\LaravelRag\Exceptions\EmbeddingServiceException;
use Moneo\LaravelRag\Exceptions\EmbeddingTimeoutException;
use Moneo\LaravelRag\Exceptions\GenerationException;
use Prism\Prism\Facades\Prism;

class PrismRetryHandler
{
    protected int $maxRetries = 3;

    protected int $baseDelayMs = 200;

    /**
     * Generate an embedding with retry and error classification.
     *
     * @param  string  $text  The text to embed
     * @param  string  $driver  The embedding driver
     * @param  string  $model  The embedding model
     * @param  int|null  $expectedDimensions  Expected vector dimensions (null to skip check)
     * @return array<int, float>
     *
     * @throws EmbeddingRateLimitException
     * @throws EmbeddingServiceException
     * @throws EmbeddingTimeoutException
     * @throws EmbeddingResponseException
     * @throws DimensionMismatchException
     */
    public function embed(string $text, string $driver, string $model, ?int $expectedDimensions = null): array
    {
        return $this->retry(function () use ($text, $driver, $model, $expectedDimensions) {
            $response = Prism::embeddings()
                ->using($driver, $model)
                ->fromInput($text)
                ->generate();

            $vector = $response->embeddings[0]->embedding;

            if ($expectedDimensions !== null && count($vector) !== $expectedDimensions) {
                $actualCount = count($vector);

                throw (new DimensionMismatchException(
                    "Embedding API returned {$actualCount} dimensions, expected {$expectedDimensions}."
                ))->withContext(['driver' => $driver, 'model' => $model, 'expected' => $expectedDimensions, 'actual' => $actualCount]);
            }

            return $vector;
        }, 'embedding');
    }

    /**
     * Generate text with retry and error classification.
     *
     * @param  string  $provider  The LLM provider
     * @param  string  $model  The LLM model
     * @param  string  $systemPrompt  The system prompt
     * @param  string  $userPrompt  The user prompt
     *
     * @throws GenerationException
     */
    public function generate(string $provider, string $model, string $systemPrompt, string $userPrompt): string
    {
        return $this->retry(function () use ($provider, $model, $systemPrompt, $userPrompt) {
            $response = Prism::text()
                ->using($provider, $model)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($userPrompt)
                ->generate();

            return $response->text;
        }, 'generation');
    }

    /**
     * Execute a callable with retry logic and exception classification.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @param  string  $operationType  'embedding' or 'generation'
     * @return T
     */
    protected function retry(callable $operation, string $operationType): mixed
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $lastException = $e;
                $classified = $this->classify($e, $operationType);

                RagLogger::warning("prism retry attempt", [
                    'operation' => $operationType,
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->maxRetries,
                    'exception_class' => $e::class,
                    'error_message' => $e->getMessage(),
                ]);

                // Non-retryable errors throw immediately
                if ($classified instanceof EmbeddingRateLimitException && $attempt < $this->maxRetries) {
                    $this->sleep($this->calculateDelay($attempt));

                    continue;
                }

                if ($this->isRetryable($e) && $attempt < $this->maxRetries) {
                    $this->sleep($this->calculateDelay($attempt));

                    continue;
                }

                throw $classified;
            }
        }

        // Should not reach here, but safety net
        throw $this->classify($lastException, $operationType);
    }

    /**
     * Classify a raw exception into a domain exception.
     */
    protected function classify(\Throwable $e, string $operationType): \Throwable
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // Rate limit detection
        if ($code === 429 || str_contains(strtolower($message), 'rate limit')) {
            return (new EmbeddingRateLimitException(
                "Rate limited by {$operationType} API: {$message}",
                429,
                $e,
            ))->withContext(['operation' => $operationType]);
        }

        // Timeout detection
        if (str_contains(strtolower($message), 'timeout') || str_contains(strtolower($message), 'timed out')) {
            return (new EmbeddingTimeoutException(
                "{$operationType} API timed out: {$message}",
                0,
                $e,
            ))->withContext(['operation' => $operationType]);
        }

        // Server error detection
        if ($code >= 500 && $code < 600) {
            return (new EmbeddingServiceException(
                "{$operationType} API server error ({$code}): {$message}",
                $code,
                $e,
            ))->withContext(['operation' => $operationType, 'status_code' => $code]);
        }

        // JSON/response parse error
        if (str_contains(strtolower($message), 'json') || str_contains(strtolower($message), 'decode')) {
            return (new EmbeddingResponseException(
                "{$operationType} API returned malformed response: {$message}",
                0,
                $e,
            ))->withContext(['operation' => $operationType]);
        }

        // Generic classification
        if ($operationType === 'generation') {
            return (new GenerationException($message, $code, $e))
                ->withContext(['operation' => $operationType]);
        }

        return (new EmbeddingServiceException($message, $code, $e))
            ->withContext(['operation' => $operationType]);
    }

    /**
     * Determine if an exception is retryable.
     */
    protected function isRetryable(\Throwable $e): bool
    {
        $code = $e->getCode();

        // Server errors are retryable
        if ($code >= 500 && $code < 600) {
            return true;
        }

        $message = strtolower($e->getMessage());

        // Timeout and connection errors are retryable
        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'connection')
            || str_contains($message, 'reset by peer');
    }

    /**
     * Calculate delay in milliseconds with exponential backoff and jitter.
     */
    protected function calculateDelay(int $attempt): int
    {
        $delay = $this->baseDelayMs * (2 ** $attempt);
        $jitter = random_int(0, (int) ($delay * 0.3));

        return $delay + $jitter;
    }

    /**
     * Sleep for a given number of milliseconds. Extracted for testability.
     */
    protected function sleep(int $ms): void
    {
        usleep($ms * 1000);
    }
}
