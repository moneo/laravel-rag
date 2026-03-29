<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Pipeline;

use Illuminate\Support\Collection;
use Moneo\LaravelRag\Agentic\AgenticResult;
use Moneo\LaravelRag\Agentic\AgenticRetriever;
use Moneo\LaravelRag\Cache\EmbeddingCache;
use Moneo\LaravelRag\Search\HybridSearch;
use Moneo\LaravelRag\Search\Reranker;
use Moneo\LaravelRag\Security\InputSanitiser;
use Moneo\LaravelRag\Streaming\RagStream;
use Moneo\LaravelRag\Support\PrismRetryHandler;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

class RagPipeline
{
    protected ?string $modelClass = null;

    protected int $limit;

    protected float $threshold = 0.0;

    /** @var array<string, mixed> */
    protected array $filters = [];

    protected ?string $systemPrompt = null;

    protected ?string $provider = null;

    protected ?string $model = null;

    protected bool $useHybrid = false;

    protected float $semanticWeight = 0.7;

    protected float $fulltextWeight = 0.3;

    protected bool $useReranking = false;

    protected ?int $rerankTopK = null;

    protected bool $stream = false;

    protected bool $includesSources = false;

    public function __construct(
        protected readonly VectorStoreContract $vectorStore,
        protected readonly EmbeddingCache $embeddingCache,
        protected readonly HybridSearch $hybridSearch,
        protected readonly Reranker $reranker,
        protected readonly ?PrismRetryHandler $prismRetryHandler = null,
    ) {
        $this->limit = (int) config('rag.search.default_limit', 5);
    }

    /**
     * Get the retry handler, creating a default if none was injected.
     */
    protected function prism(): PrismRetryHandler
    {
        return $this->prismRetryHandler ?? new PrismRetryHandler;
    }

    /**
     * Set the source model class for retrieval.
     */
    public function from(string $modelClass): static
    {
        $clone = clone $this;
        $clone->modelClass = $modelClass;

        return $clone;
    }

    /**
     * Set the maximum number of chunks to retrieve.
     */
    public function limit(int $limit): static
    {
        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    /**
     * Set the minimum similarity threshold.
     */
    public function threshold(float $threshold): static
    {
        $clone = clone $this;
        $clone->threshold = $threshold;

        return $clone;
    }

    /**
     * Filter results by metadata.
     *
     * @param  array<string, mixed>  $filters
     */
    public function filter(array $filters): static
    {
        $clone = clone $this;
        $clone->filters = $filters;

        return $clone;
    }

    /**
     * Set the system prompt for the LLM.
     */
    public function systemPrompt(string $prompt): static
    {
        $clone = clone $this;
        $clone->systemPrompt = $prompt;

        return $clone;
    }

    /**
     * Set the LLM provider and model.
     */
    public function using(string $provider, string $model): static
    {
        $clone = clone $this;
        $clone->provider = $provider;
        $clone->model = $model;

        return $clone;
    }

    /**
     * Enable hybrid search with weights.
     */
    public function hybrid(float $semanticWeight = 0.7, float $fulltextWeight = 0.3): static
    {
        $clone = clone $this;
        $clone->useHybrid = true;
        $clone->semanticWeight = $semanticWeight;
        $clone->fulltextWeight = $fulltextWeight;

        return $clone;
    }

    /**
     * Enable re-ranking of retrieved chunks.
     */
    public function rerank(?int $topK = null): static
    {
        $clone = clone $this;
        $clone->useReranking = true;
        $clone->rerankTopK = $topK;

        return $clone;
    }

    /**
     * Ask a question and get an answer.
     *
     * @param  string  $question  The user's question
     */
    public function ask(string $question): RagResult
    {
        $startTime = microtime(true);

        // Step 1: Retrieve relevant chunks
        $chunks = $this->retrieve($question);

        $retrievalMs = (microtime(true) - $startTime) * 1000;

        // Step 2: Build context from chunks
        $context = $this->buildContext($chunks);

        // Step 3: Generate answer via LLM
        $genStart = microtime(true);
        $answer = $this->generate($question, $context);
        $generationMs = (microtime(true) - $genStart) * 1000;

        return new RagResult(
            answer: $answer,
            chunks: $chunks,
            question: $question,
            retrievalTimeMs: $retrievalMs,
            generationTimeMs: $generationMs,
        );
    }

    /**
     * Ask and include source references.
     */
    public function askWithSources(string $question): RagResult
    {
        $this->includesSources = true;

        return $this->ask($question);
    }

    /**
     * Perform a dry run — retrieve chunks without generating an answer.
     *
     * @return Collection<int, array{id: string, score: float, metadata: array, content: string}>
     */
    public function dryRun(string $question): Collection
    {
        return $this->retrieve($question);
    }

    /**
     * Enable agentic RAG with iterative retrieval.
     *
     * @param  int  $maxSteps  Maximum retrieval iterations
     */
    public function agentic(int $maxSteps = 3): AgenticRetriever
    {
        return new AgenticRetriever(
            pipeline: $this,
            maxSteps: $maxSteps,
        );
    }

    /**
     * Get a streaming RAG response.
     */
    public function stream(string $question): RagStream
    {
        $chunks = $this->retrieve($question);
        $context = $this->buildContext($chunks);

        return new RagStream(
            question: $question,
            context: $context,
            chunks: $chunks,
            systemPrompt: $this->systemPrompt,
            provider: $this->provider ?? config('rag.llm.provider'),
            model: $this->model ?? config('rag.llm.model'),
        );
    }

    /**
     * Retrieve relevant chunks for a question.
     *
     * @return Collection<int, array{id: string, score: float, metadata: array, content: string}>
     */
    public function retrieve(string $question): Collection
    {
        $vector = $this->embed($question);
        $table = $this->resolveTable();
        $store = $this->vectorStore->table($table);

        $retrieveLimit = $this->useReranking ? $this->limit * 4 : $this->limit;

        if ($this->useHybrid) {
            $chunks = $this->hybridSearch->search(
                table: $table,
                query: $question,
                vector: $vector,
                semanticWeight: $this->semanticWeight,
                fulltextWeight: $this->fulltextWeight,
                limit: $retrieveLimit,
            );
        } else {
            $chunks = $store->similaritySearch($vector, $retrieveLimit, $this->threshold);
        }

        // Apply metadata filters
        if ($this->filters !== []) {
            $chunks = $chunks->filter(function (array $chunk): bool {
                foreach ($this->filters as $key => $value) {
                    if (($chunk['metadata'][$key] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            })->values();
        }

        // Re-rank if enabled
        if ($this->useReranking) {
            $chunks = $this->reranker->rerank(
                query: $question,
                chunks: $chunks,
                topK: $this->rerankTopK,
            );
        }

        return $chunks->take($this->limit);
    }

    /**
     * Generate an embedding vector for a query.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        // Check cache
        if ($this->embeddingCache->enabled) {
            $cached = $this->embeddingCache->get($text);

            if ($cached !== null) {
                return $cached;
            }
        }

        $config = config('rag.embedding');

        $vector = $this->prism()->embed(
            text: $text,
            driver: $config['driver'],
            model: $config['model'],
            expectedDimensions: (int) $config['dimensions'],
        );

        if ($this->embeddingCache->enabled) {
            $this->embeddingCache->put($text, $vector);
        }

        return $vector;
    }

    /**
     * Build LLM context from retrieved chunks.
     */
    protected function buildContext(Collection $chunks): string
    {
        if ($chunks->isEmpty()) {
            return 'No relevant context found.';
        }

        return $chunks->map(function (array $chunk, int $index) {
            $source = $chunk['metadata']['source'] ?? 'Unknown';
            $content = $chunk['content'] ?? ($chunk['metadata']['content'] ?? '');

            if ($this->includesSources) {
                return "[Source {$index}: {$source}]\n{$content}";
            }

            return $content;
        })->implode("\n\n---\n\n");
    }

    /**
     * Generate an answer using the LLM.
     */
    protected function generate(string $question, string $context): string
    {
        $provider = $this->provider ?? config('rag.llm.provider');
        $model = $this->model ?? config('rag.llm.model');

        $systemPromptText = $this->systemPrompt ?? 'You are a helpful assistant. Answer the question based on the provided context. If the context does not contain enough information, say so.';

        $fullPrompt = "{$systemPromptText}\n\nContext:\n{$context}";

        // Sanitise user input before passing to LLM
        $sanitisedQuestion = InputSanitiser::clean($question);

        return $this->prism()->generate($provider, $model, $fullPrompt, $sanitisedQuestion);
    }

    /**
     * Resolve the table name from the model class.
     */
    protected function resolveTable(): string
    {
        if ($this->modelClass) {
            return (new $this->modelClass)->getTable();
        }

        return 'documents';
    }
}
