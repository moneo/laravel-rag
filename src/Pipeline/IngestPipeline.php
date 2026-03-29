<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Pipeline;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Moneo\LaravelRag\Cache\EmbeddingCache;
use Moneo\LaravelRag\Chunking\ChunkingFactory;
use Moneo\LaravelRag\Events\EmbeddingCacheHit;
use Moneo\LaravelRag\Events\EmbeddingGenerated;
use Moneo\LaravelRag\Support\PrismRetryHandler;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

class IngestPipeline
{
    protected ?string $content = null;

    protected ?string $filePath = null;

    /** @var array<int, array{strategy: string, options: array}> */
    protected array $chunkSteps = [];

    protected ?string $targetModel = null;

    protected bool $async = false;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    public function __construct(
        protected readonly VectorStoreContract $vectorStore,
        protected readonly ChunkingFactory $chunkingFactory,
        protected readonly EmbeddingCache $embeddingCache,
        protected readonly ?PrismRetryHandler $prismRetryHandler = null,
    ) {}

    /**
     * Resolve the PrismRetryHandler instance.
     */
    protected function prism(): PrismRetryHandler
    {
        return $this->prismRetryHandler ?? app(PrismRetryHandler::class);
    }

    /**
     * Set the source file path.
     *
     * @return $this
     */
    public function file(string $path): static
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        $this->filePath = $path;
        $this->content = file_get_contents($path);

        return $this;
    }

    /**
     * Set the source text directly.
     *
     * @return $this
     */
    public function text(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Add a chunking step to the pipeline.
     *
     * @param  string  $strategy  The chunking strategy name
     * @param  int|null  $size  Chunk size (characters)
     * @param  int|null  $overlap  Overlap between chunks
     * @param  float|null  $threshold  Similarity threshold (for semantic chunker)
     * @return $this
     */
    public function chunk(
        string $strategy = 'character',
        ?int $size = null,
        ?int $overlap = null,
        ?float $threshold = null,
    ): static {
        $options = array_filter([
            'size' => $size,
            'overlap' => $overlap,
            'threshold' => $threshold,
        ], fn (int|float|null $v): bool => $v !== null);

        $this->chunkSteps[] = [
            'strategy' => $strategy,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * Set the target Eloquent model class for storage.
     *
     * @return $this
     */
    public function storeIn(string $modelClass): static
    {
        $this->targetModel = $modelClass;

        return $this;
    }

    /**
     * Add metadata to each ingested chunk.
     *
     * @param  array<string, mixed>  $metadata
     * @return $this
     */
    public function withMetadata(array $metadata): static
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    /**
     * Execute the ingest pipeline synchronously.
     *
     * @return array<int, string> The IDs of the ingested chunks
     */
    public function run(): array
    {
        $chunks = $this->processChunks();

        return $this->storeChunks($chunks);
    }

    /**
     * Dispatch the ingest pipeline as a queued job.
     */
    public function dispatch(): void
    {
        $content = $this->content;
        $chunkSteps = $this->chunkSteps;
        $targetModel = $this->targetModel;
        $metadata = $this->metadata;

        dispatch(function () use ($content, $chunkSteps, $targetModel, $metadata): void {
            $pipeline = app(self::class);
            $pipeline->content = $content;
            $pipeline->chunkSteps = $chunkSteps;
            $pipeline->targetModel = $targetModel;
            $pipeline->metadata = $metadata;
            $pipeline->run();
        });
    }

    /**
     * Process the content through all chunking steps.
     *
     * @return array<int, string>
     */
    protected function processChunks(): array
    {
        if (in_array($this->content, [null, '', '0'], true)) {
            return [];
        }

        // Use default strategy if none specified
        if ($this->chunkSteps === []) {
            $this->chunkSteps[] = [
                'strategy' => config('rag.ingest.chunk_strategy', 'character'),
                'options' => [
                    'size' => (int) config('rag.ingest.chunk_size', 500),
                    'overlap' => (int) config('rag.ingest.chunk_overlap', 50),
                ],
            ];
        }

        // Apply the last chunking strategy (they override each other)
        $lastStep = end($this->chunkSteps);
        $chunker = $this->chunkingFactory->make($lastStep['strategy']);

        return $chunker->chunk($this->content, $lastStep['options']);
    }

    /**
     * Generate embeddings and store chunks in the vector store.
     *
     * @param  array<int, string>  $chunks
     * @return array<int, string> The IDs of stored chunks
     */
    protected function storeChunks(array $chunks): array
    {
        $table = $this->resolveTable();
        $store = $this->vectorStore->table($table);
        $config = config('rag.embedding');

        return DB::transaction(function () use ($chunks, $store, $config): array {
            $ids = [];

            foreach ($chunks as $index => $chunk) {
                $id = Str::uuid()->toString();
                $vector = null;

                // Check cache first
                if ($this->embeddingCache->enabled) {
                    $vector = $this->embeddingCache->get($chunk);

                    if ($vector !== null) {
                        event(new EmbeddingCacheHit(null, $chunk));
                    }
                }

                // Generate embedding if not cached
                if ($vector === null) {
                    $vector = $this->prism()->embed($chunk, $config['driver'], $config['model'], (int) $config['dimensions']);

                    if ($this->embeddingCache->enabled) {
                        $this->embeddingCache->put($chunk, $vector);
                    }

                    event(new EmbeddingGenerated(null, $chunk, $vector));
                }

                $metadata = array_merge($this->metadata, [
                    'content' => $chunk,
                    'chunk_index' => $index,
                    'source' => $this->filePath,
                ]);

                $store->upsert($id, $vector, $metadata);
                $ids[] = $id;
            }

            return $ids;
        });
    }

    /**
     * Resolve the target table name.
     */
    protected function resolveTable(): string
    {
        if ($this->targetModel) {
            return (new $this->targetModel)->getTable();
        }

        return 'documents';
    }
}
