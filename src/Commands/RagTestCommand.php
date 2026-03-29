<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Commands;

use Illuminate\Console\Command;
use Moneo\LaravelRag\Exceptions\RagException;
use Moneo\LaravelRag\Facades\Rag;

class RagTestCommand extends Command
{
    protected $signature = 'rag:test
        {question : The question to ask}
        {--model= : The Eloquent model class to query}
        {--limit=5 : Maximum number of chunks to retrieve}
        {--rerank : Enable re-ranking}
        {--hybrid : Enable hybrid search}
        {--dry-run : Only retrieve, do not generate}';

    protected $description = 'Test the RAG pipeline with a question';

    public function handle(): int
    {
        $question = $this->argument('question');
        $modelClass = $this->option('model');
        $limit = (int) $this->option('limit');
        $useRerank = (bool) $this->option('rerank');
        $useHybrid = (bool) $this->option('hybrid');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $pipeline = Rag::from($modelClass ?? 'App\\Models\\Document')
                ->limit($limit);

            if ($useHybrid) {
                $pipeline = $pipeline->hybrid();
            }

            if ($useRerank) {
                $pipeline = $pipeline->rerank();
            }

            if ($dryRun) {
                $this->info('Dry run — retrieving chunks only...');
                $chunks = $pipeline->dryRun($question);

                $this->table(
                    ['#', 'ID', 'Score', 'Content Preview'],
                    $chunks->map(fn (array $chunk, int $i): array => [
                        $i + 1,
                        mb_substr((string) $chunk['id'], 0, 8).'...',
                        number_format($chunk['score'], 4),
                        mb_substr($chunk['content'] ?? '', 0, 80),
                    ])->toArray()
                );

                return self::SUCCESS;
            }

            $this->info('Querying...');
            $result = $pipeline->ask($question);

            $this->newLine();
            $this->line("<fg=green>Answer:</>");
            $this->line($result->answer);

            $this->newLine();
            $this->line("<fg=yellow>Sources:</>");
            $this->table(
                ['#', 'Score', 'Source', 'Preview'],
                $result->sources()->map(fn (array $source, int $i): array => [
                    $i + 1,
                    number_format($source['score'], 4),
                    mb_substr($source['source'] ?? 'N/A', 0, 30),
                    mb_substr((string) $source['preview'], 0, 60),
                ])->toArray()
            );

            $this->newLine();
            $this->info(sprintf(
                'Timing: retrieval %.0fms, generation %.0fms, total %.0fms',
                $result->retrievalTimeMs,
                $result->generationTimeMs,
                $result->totalTimeMs(),
            ));

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
