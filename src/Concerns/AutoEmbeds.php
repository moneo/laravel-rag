<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Concerns;

use Moneo\LaravelRag\Cache\EmbeddingCache;
use Moneo\LaravelRag\Events\EmbeddingCacheHit;
use Moneo\LaravelRag\Events\EmbeddingGenerated;
use Moneo\LaravelRag\Support\PrismRetryHandler;

/**
 * Automatically generate embeddings when a model is created or updated.
 *
 * Model properties:
 * - string|array $embedSource    = 'content'   — column(s) to embed
 * - string       $vectorColumn   = 'embedding' — column to store embedding
 * - bool         $embedAsync     = false        — dispatch as queued job
 * - bool         $embedCache     = true         — use embedding cache
 */
trait AutoEmbeds
{
    public static function bootAutoEmbeds(): void
    {
        static::saving(function ($model): void {
            if ($model->shouldGenerateEmbedding() && ! $model->getEmbedAsync()) {
                $model->generateAndStoreEmbedding();
            }
        });

        static::saved(function ($model): void {
            if ($model->getEmbedAsync() && $model->shouldGenerateEmbedding()) {
                dispatch(function () use ($model): void {
                    $model->generateAndStoreEmbedding();
                });
            }
        });
    }

    /**
     * Determine if an embedding should be generated.
     */
    public function shouldGenerateEmbedding(): bool
    {
        $sourceColumns = (array) $this->getEmbedSource();

        foreach ($sourceColumns as $column) {
            if ($this->isDirty($column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate the embedding and store it on the model.
     */
    public function generateAndStoreEmbedding(): void
    {
        $sourceText = $this->getEmbedSourceText();

        if (in_array(trim((string) $sourceText), ['', '0'], true)) {
            return;
        }

        $cache = app(EmbeddingCache::class);
        $vector = null;

        if ($this->getEmbedCacheEnabled() && $cache->enabled) {
            $vector = $cache->get($sourceText);

            if ($vector !== null) {
                event(new EmbeddingCacheHit($this, $sourceText));
            }
        }

        if ($vector === null) {
            $vector = $this->callEmbeddingApi($sourceText);

            if ($this->getEmbedCacheEnabled() && $cache->enabled) {
                $cache->put($sourceText, $vector);
            }

            event(new EmbeddingGenerated($this, $sourceText, $vector));
        }

        $this->setAttribute($this->getVectorColumnName(), $vector);
    }

    /**
     * Get the source text to embed.
     */
    public function getEmbedSourceText(): string
    {
        $sources = (array) $this->getEmbedSource();

        return collect($sources)
            ->map(fn (string $column) => $this->getAttribute($column) ?? '')
            ->filter(fn (string $text): bool => !in_array(trim($text), ['', '0'], true))
            ->implode("\n\n");
    }

    /**
     * Call the embedding API via Prism.
     *
     * @return array<int, float>
     */
    protected function callEmbeddingApi(string $text): array
    {
        $config = config('rag.embedding');

        return app(PrismRetryHandler::class)->embed($text, $config['driver'], $config['model'], (int) $config['dimensions']);
    }

    /**
     * Get the embed source column(s).
     *
     * @return string|array<int, string>
     */
    public function getEmbedSource(): string|array
    {
        return $this->embedSource ?? 'content';
    }

    /**
     * Get the vector column name.
     */
    public function getVectorColumnName(): string
    {
        return $this->vectorColumn ?? 'embedding';
    }

    /**
     * Whether embedding should be dispatched async.
     */
    public function getEmbedAsync(): bool
    {
        return $this->embedAsync ?? false;
    }

    /**
     * Whether embedding cache is enabled for this model.
     */
    public function getEmbedCacheEnabled(): bool
    {
        return $this->embedCache ?? true;
    }
}
