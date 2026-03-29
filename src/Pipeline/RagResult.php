<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Pipeline;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * @implements Arrayable<string, mixed>
 */
class RagResult implements Arrayable
{
    /**
     * @param  string  $answer  The generated answer
     * @param  Collection<int, array{id: string, score: float, metadata: array, content: string}>  $chunks  The retrieved chunks
     * @param  string  $question  The original question
     * @param  float  $retrievalTimeMs  Retrieval time in milliseconds
     * @param  float  $generationTimeMs  Generation time in milliseconds
     */
    public function __construct(
        public readonly string $answer,
        public readonly Collection $chunks,
        public readonly string $question,
        public readonly float $retrievalTimeMs,
        public readonly float $generationTimeMs,
    ) {}

    /**
     * Get source references from the chunks.
     *
     * @return Collection<int, array{source: string, score: float, preview: string}>
     */
    public function sources(): Collection
    {
        return $this->chunks->map(fn (array $chunk): array => [
            'source' => (string) ($chunk['metadata']['source'] ?? 'Unknown'),
            'score' => $chunk['score'],
            'preview' => mb_substr((string) $chunk['content'], 0, 200),
        ]);
    }

    /**
     * Get total time in milliseconds.
     */
    public function totalTimeMs(): float
    {
        return $this->retrievalTimeMs + $this->generationTimeMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'question' => $this->question,
            'sources' => $this->sources()->toArray(),
            'timing' => [
                'retrieval_ms' => round($this->retrievalTimeMs, 2),
                'generation_ms' => round($this->generationTimeMs, 2),
                'total_ms' => round($this->totalTimeMs(), 2),
            ],
        ];
    }
}
