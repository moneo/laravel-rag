<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Commands;

use Illuminate\Console\Command;
use Moneo\LaravelRag\Exceptions\RagException;

class RagEstimateCommand extends Command
{
    protected $signature = 'rag:estimate
        {--model= : The Eloquent model class}';

    protected $description = 'Estimate embedding costs and storage for a model';

    public function handle(): int
    {
        $modelClass = $this->option('model') ?? 'App\\Models\\Document';

        if (! class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");

            return self::FAILURE;
        }

        try {
            $model = new $modelClass;
            $embedSource = $model->embedSource ?? 'content';
            $sourceColumns = (array) $embedSource;
            $dimensions = (int) config('rag.embedding.dimensions', 1536);

            $total = $modelClass::count();
            $this->info("Model: {$modelClass}");
            $this->info("Total records: {$total}");

            // Sample text length
            $sample = $modelClass::query()->take(100)->get();
            $avgChars = $sample->avg(fn($record) => collect($sourceColumns)
                ->map(fn ($col): int => mb_strlen($record->getAttribute($col) ?? ''))
                ->sum());

            $avgTokens = $avgChars / 4; // rough estimate
            $totalTokens = $avgTokens * $total;

            // OpenAI text-embedding-3-small pricing: $0.02 per 1M tokens
            $estimatedCost = ($totalTokens / 1_000_000) * 0.02;

            // Storage: each float is 4 bytes
            $storagePerRecord = $dimensions * 4; // bytes
            $totalStorageMb = ($storagePerRecord * $total) / (1024 * 1024);

            $this->newLine();
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Records', number_format($total)],
                    ['Avg chars/record', number_format($avgChars, 0)],
                    ['Avg tokens/record', number_format($avgTokens, 0)],
                    ['Total tokens', number_format($totalTokens, 0)],
                    ['Dimensions', number_format($dimensions)],
                    ['Est. API cost (text-embedding-3-small)', '$'.number_format($estimatedCost, 4)],
                    ['Est. vector storage', number_format($totalStorageMb, 2).' MB'],
                ]
            );

            return self::SUCCESS;
        } catch (RagException $e) {
            $this->error("RAG error: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
