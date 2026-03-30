<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Commands;

use Illuminate\Console\Command;
use Moneo\LaravelRag\Cache\EmbeddingCache;
use Moneo\LaravelRag\Exceptions\RagException;
use Moneo\LaravelRag\Support\PrismRetryHandler;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

class VectorizeIndexCommand extends Command
{
    protected $signature = 'rag:index
        {model : The Eloquent model class to index}
        {--chunk=100 : Number of records to process per batch}
        {--queue : Dispatch indexing as a queued job}';

    protected $description = 'Generate embeddings and index model records into the vector store';

    public function handle(VectorStoreContract $vectorStore, EmbeddingCache $embeddingCache, PrismRetryHandler $prism): int
    {
        $modelClass = $this->argument('model');
        $chunkSize = (int) $this->option('chunk');

        if (! class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");

            return self::FAILURE;
        }

        try {
            return $this->indexModel($modelClass, $chunkSize, $vectorStore, $embeddingCache, $prism);
        } catch (RagException $e) {
            $this->newLine();
            $this->error("RAG error: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function indexModel(
        string $modelClass,
        int $chunkSize,
        VectorStoreContract $vectorStore,
        EmbeddingCache $embeddingCache,
        PrismRetryHandler $prism,
    ): int {
        $model = new $modelClass;
        $table = $model->getTable();

        // FIX BUG-A: Use public getter instead of direct protected property access
        // AutoEmbeds trait provides getEmbedSource(), fall back to 'content' if not available
        $embedSource = method_exists($model, 'getEmbedSource')
            ? $model->getEmbedSource()
            : 'content';

        $store = $vectorStore->table($table);

        $total = $modelClass::count();
        $this->info("Indexing {$total} records from {$modelClass}...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $config = config('rag.embedding');
        $indexed = 0;
        $cacheHits = 0;

        $modelClass::query()->chunk($chunkSize, function ($records) use (
            $store, $embeddingCache, $prism, $config, $embedSource, &$indexed, &$cacheHits, $bar,
        ): void {
            foreach ($records as $record) {
                $sourceColumns = (array) $embedSource;
                $text = collect($sourceColumns)
                    ->map(fn (string $col) => $record->getAttribute($col) ?? '')
                    ->filter()
                    ->implode("\n\n");

                if (in_array(trim($text), ['', '0'], true)) {
                    $bar->advance();

                    continue;
                }

                // Check cache
                $vector = null;
                if ($embeddingCache->enabled) {
                    $vector = $embeddingCache->get($text);
                    if ($vector !== null) {
                        $cacheHits++;
                    }
                }

                // Generate if not cached
                if ($vector === null) {
                    $vector = $prism->embed($text, $config['driver'], $config['model'], (int) $config['dimensions']);

                    if ($embeddingCache->enabled) {
                        $embeddingCache->put($text, $vector);
                    }
                }

                // FIX BUG-B: Use updateEmbedding() instead of upsert()
                // upsert() does INSERT which fails on NOT NULL columns (name, nationality, etc.)
                // updateEmbedding() only updates the embedding column on existing records
                $store->updateEmbedding(
                    id: (string) $record->getKey(),
                    vector: $vector,
                    metadata: [
                        'content' => $text,
                        'model' => $record::class,
                        'id' => $record->getKey(),
                    ],
                );

                $indexed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Indexed {$indexed} records. Cache hits: {$cacheHits}.");

        return self::SUCCESS;
    }
}
