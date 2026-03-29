<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\DevTools;

use DebugBar\DataCollector\DataCollector;
use DebugBar\DataCollector\Renderable;
use Illuminate\Support\Facades\Event;
use Moneo\LaravelRag\Cache\EmbeddingCache;
use Moneo\LaravelRag\Events\EmbeddingCacheHit;
use Moneo\LaravelRag\Events\EmbeddingGenerated;

class DebugbarCollector extends DataCollector implements Renderable
{
    protected int $queryCount = 0;

    protected int $chunksRetrieved = 0;

    protected float $retrievalMs = 0;

    protected float $generationMs = 0;

    protected int $embeddingsGenerated = 0;

    protected int $cacheHits = 0;

    public function __construct()
    {
        $this->listenToEvents();
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $cache = app(EmbeddingCache::class);

        return [
            'query_count' => $this->queryCount,
            'chunks_retrieved' => $this->chunksRetrieved,
            'embeddings_generated' => $this->embeddingsGenerated,
            'cache_hits' => $cache->getHits(),
            'cache_misses' => $cache->getMisses(),
            'cache_hit_rate' => number_format($cache->getHitRate() * 100, 1).'%',
            'retrieval_ms' => number_format($this->retrievalMs, 2),
            'generation_ms' => number_format($this->generationMs, 2),
        ];
    }

    public function getName(): string
    {
        return 'rag';
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgets(): array
    {
        return [
            'rag' => [
                'icon' => 'search',
                'tooltip' => 'RAG Pipeline',
                'widget' => 'PhpDebugBar.Widgets.VariableListWidget',
                'map' => 'rag',
                'default' => '{}',
            ],
            'rag:badge' => [
                'map' => 'rag.query_count',
                'default' => 0,
            ],
        ];
    }

    protected function listenToEvents(): void
    {
        Event::listen(EmbeddingGenerated::class, function (): void {
            $this->embeddingsGenerated++;
        });

        Event::listen(EmbeddingCacheHit::class, function (): void {
            $this->cacheHits++;
        });
    }

    /**
     * Record a RAG query execution.
     */
    public function recordQuery(int $chunks, float $retrievalMs, float $generationMs): void
    {
        $this->queryCount++;
        $this->chunksRetrieved += $chunks;
        $this->retrievalMs += $retrievalMs;
        $this->generationMs += $generationMs;
    }
}
