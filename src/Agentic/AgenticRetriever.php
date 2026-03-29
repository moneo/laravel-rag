<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Agentic;

use Illuminate\Support\Collection;
use Moneo\LaravelRag\Pipeline\RagPipeline;
use Moneo\LaravelRag\Support\PrismRetryHandler;

class AgenticRetriever
{
    /**
     * @param  RagPipeline  $pipeline  The base RAG pipeline
     * @param  int  $maxSteps  Maximum retrieval iterations
     */
    public function __construct(
        protected readonly RagPipeline $pipeline,
        protected readonly int $maxSteps = 3,
    ) {}

    /**
     * Ask a question using iterative retrieval.
     *
     * Loop: retrieve → evaluate sufficiency → re-query if needed → final answer.
     */
    public function ask(string $question): AgenticResult
    {
        $startTime = microtime(true);
        $allChunks = collect();
        $steps = [];
        $currentQuery = $question;

        for ($step = 0; $step < $this->maxSteps; $step++) {
            // Retrieve chunks for current query
            $chunks = $this->pipeline->retrieve($currentQuery);

            $allChunks = $allChunks->merge($chunks)->unique('id')->values();

            $steps[] = [
                'query' => $currentQuery,
                'chunks_retrieved' => $chunks->count(),
                'sufficient' => false,
            ];

            // Evaluate if we have sufficient context
            $evaluation = $this->evaluateSufficiency($question, $allChunks);

            if ($evaluation['sufficient']) {
                $steps[count($steps) - 1]['sufficient'] = true;

                break;
            }

            // Generate a refined query for the next iteration
            if ($step < $this->maxSteps - 1 && ! empty($evaluation['refined_query'])) {
                $currentQuery = $evaluation['refined_query'];
            }
        }

        // Generate final answer with all collected chunks
        $context = $allChunks->map(fn (array $chunk): string => $chunk['content'] ?? ($chunk['metadata']['content'] ?? ''))->implode("\n\n---\n\n");

        $answer = $this->generateFinalAnswer($question, $context);

        $totalTimeMs = (microtime(true) - $startTime) * 1000;

        return new AgenticResult(
            answer: $answer,
            steps: $steps,
            totalChunksRetrieved: $allChunks->count(),
            allChunks: $allChunks,
            totalTimeMs: $totalTimeMs,
        );
    }

    /**
     * Evaluate whether the retrieved context is sufficient to answer the question.
     *
     * @return array{sufficient: bool, refined_query: string|null}
     */
    protected function evaluateSufficiency(string $question, Collection $chunks): array
    {
        $context = $chunks->map(fn (array $c) => $c['content'] ?? '')->implode("\n\n");

        $provider = config('rag.llm.provider');
        $model = config('rag.llm.model');

        $response = app(PrismRetryHandler::class)->generate($provider, $model, 'You are an evaluator. Given a question and retrieved context, determine:
1. Is the context SUFFICIENT to answer the question fully? (yes/no)
2. If not, what specific information is missing? Provide a refined search query.

Respond in JSON format: {"sufficient": true/false, "refined_query": "..." or null}', "Question: {$question}\n\nContext:\n{$context}");

        $parsed = json_decode($response, true);

        return [
            'sufficient' => $parsed['sufficient'] ?? false,
            'refined_query' => $parsed['refined_query'] ?? null,
        ];
    }

    /**
     * Generate the final answer from all collected context.
     */
    protected function generateFinalAnswer(string $question, string $context): string
    {
        $provider = config('rag.llm.provider');
        $model = config('rag.llm.model');

        return app(PrismRetryHandler::class)->generate($provider, $model, 'You are a helpful assistant. Answer the question thoroughly based on the provided context. If the context does not contain enough information, say so clearly.', "Question: {$question}\n\nContext:\n{$context}");
    }
}
