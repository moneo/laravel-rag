<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Search;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Moneo\LaravelRag\Support\PrismRetryHandler;
use Moneo\LaravelRag\Support\ScoreParser;

class Reranker
{
    /**
     * @param  bool  $enabled  Whether reranking is enabled
     * @param  int  $topK  Number of top results to keep after reranking
     */
    public function __construct(
        protected readonly bool $enabled = false,
        protected readonly int $topK = 5,
    ) {}

    /**
     * Re-rank chunks by LLM-scored relevance to the query.
     *
     * Each chunk is scored 0-10. Results are sorted descending and truncated to topK.
     *
     * @param  string  $query  The user's question
     * @param  Collection<int, array{id: string, score: float, metadata: array, content: string}>  $chunks
     * @param  int|null  $topK  Override default topK
     * @return Collection<int, array{id: string, score: float, metadata: array, content: string}>
     */
    public function rerank(string $query, Collection $chunks, ?int $topK = null): Collection
    {
        if (! $this->enabled || $chunks->isEmpty()) {
            return $chunks;
        }

        $topK ??= $this->topK;

        $scored = $chunks->map(function (array $chunk) use ($query): array {
            $content = $chunk['content'] ?? ($chunk['metadata']['content'] ?? '');
            $cacheKey = 'rag_rerank:'.hash('sha256', $query.$content);

            $score = Cache::remember($cacheKey, 3600, fn(): float => $this->scoreChunk($query, $content));

            return array_merge($chunk, ['rerank_score' => $score]);
        });

        return $scored
            ->sortByDesc('rerank_score')
            ->take($topK)
            ->values();
    }

    /**
     * Score a single chunk's relevance to the query (0-10).
     */
    protected function scoreChunk(string $query, string $content): float
    {
        $provider = config('rag.llm.provider');
        $model = config('rag.llm.model');

        $response = app(PrismRetryHandler::class)->generate($provider, $model, 'You are a relevance scorer. Given a query and a text passage, rate how relevant the passage is to answering the query on a scale of 0-10. Respond with ONLY a number.', "Query: {$query}\n\nPassage: {$content}");

        return ScoreParser::parse($response, min: 0.0, max: 10.0, default: 0.0);
    }
}
