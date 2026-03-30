<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Tests\Benchmarks;

use Moneo\LaravelRag\Search\HybridSearch;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;
use PhpBench\Attributes as Bench;

class BenchmarkRRFMerge
{
    private HybridSearch $search;

    private \Illuminate\Support\Collection $semantic100;

    private \Illuminate\Support\Collection $fulltext100;

    public function __construct()
    {
        $store = new class implements VectorStoreContract
        {
            public function upsert(string $id, array $vector, array $metadata): void {}

            public function similaritySearch(array $vector, int $limit, float $threshold = 0.0): \Illuminate\Support\Collection
            {
                return collect();
            }

            public function hybridSearch(string $query, array $vector, float $semanticWeight, float $fulltextWeight, int $limit): \Illuminate\Support\Collection
            {
                return collect();
            }

            public function delete(string $id): void {}

            public function flush(string $collection): void {}

            public function updateEmbedding(string $id, array $vector, array $metadata = []): void {}

            public function table(string $table): static
            {
                return $this;
            }

            public function supportsFullTextSearch(): bool
            {
                return false;
            }
        };

        $this->search = new HybridSearch($store, 60);

        $this->semantic100 = collect(array_map(fn ($i) => [
            'id' => "s{$i}", 'score' => 1 - $i * 0.01, 'metadata' => [], 'content' => "Semantic {$i}",
        ], range(0, 99)));

        $this->fulltext100 = collect(array_map(fn ($i) => [
            'id' => "f{$i}", 'score' => 1 - $i * 0.01, 'metadata' => [], 'content' => "Fulltext {$i}",
        ], range(0, 99)));
    }

    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Warmup(2)]
    public function benchRRF100x100(): void
    {
        $this->search->mergeWithRRF($this->semantic100, $this->fulltext100, 0.7, 0.3, 10);
    }

    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Warmup(2)]
    public function benchRRF100x0(): void
    {
        $this->search->mergeWithRRF($this->semantic100, collect(), 0.7, 0.3, 10);
    }
}
