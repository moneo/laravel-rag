<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Support;

use Illuminate\Support\Facades\Log;

/**
 * Structured logging for all RAG operations.
 *
 * Every log entry includes a channel prefix 'rag.' and structured context.
 * Privacy-safe: logs text hashes, never raw user input.
 */
class RagLogger
{
    /**
     * Log an embedding operation.
     *
     * @param  array<string, mixed>  $context
     */
    public static function embedding(string $event, array $context = []): void
    {
        Log::info("rag.embedding.{$event}", self::sanitiseContext($context));
    }

    /**
     * Log a retrieval operation.
     *
     * @param  array<string, mixed>  $context
     */
    public static function retrieval(string $event, array $context = []): void
    {
        Log::info("rag.retrieval.{$event}", self::sanitiseContext($context));
    }

    /**
     * Log a generation operation.
     *
     * @param  array<string, mixed>  $context
     */
    public static function generation(string $event, array $context = []): void
    {
        Log::info("rag.generation.{$event}", self::sanitiseContext($context));
    }

    /**
     * Log an ingest operation.
     *
     * @param  array<string, mixed>  $context
     */
    public static function ingest(string $event, array $context = []): void
    {
        Log::info("rag.ingest.{$event}", self::sanitiseContext($context));
    }

    /**
     * Log a cache operation.
     *
     * @param  array<string, mixed>  $context
     */
    public static function cache(string $event, array $context = []): void
    {
        Log::debug("rag.cache.{$event}", self::sanitiseContext($context));
    }

    /**
     * Log a warning.
     *
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        Log::warning("rag.warning: {$message}", self::sanitiseContext($context));
    }

    /**
     * Log an error with exception context.
     *
     * @param  array<string, mixed>  $additionalContext
     */
    public static function error(string $operation, \Throwable $e, array $additionalContext = []): void
    {
        Log::error("rag.error.{$operation}", array_merge([
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], self::sanitiseContext($additionalContext)));
    }

    /**
     * Sanitise context for logging — hash any text fields for privacy.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected static function sanitiseContext(array $context): array
    {
        $sanitised = $context;

        // Hash text fields for privacy
        foreach (['text', 'query', 'question', 'content', 'source_text'] as $field) {
            if (isset($sanitised[$field]) && is_string($sanitised[$field])) {
                $sanitised["{$field}_hash"] = substr(hash('sha256', $sanitised[$field]), 0, 12);
                $sanitised["{$field}_length"] = mb_strlen((string) $sanitised[$field]);
                unset($sanitised[$field]);
            }
        }

        return $sanitised;
    }

    /**
     * Create a hash of text for logging (not the full text — privacy).
     */
    public static function textHash(string $text): string
    {
        return substr(hash('sha256', $text), 0, 12);
    }
}
