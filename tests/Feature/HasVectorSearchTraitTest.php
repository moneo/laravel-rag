<?php

declare(strict_types=1);

use Moneo\LaravelRag\Concerns\HasVectorSearch;
use Moneo\LaravelRag\Search\HybridSearch;
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

test('semanticSearch delegates to VectorStoreContract', function () {
    $store = Mockery::mock(VectorStoreContract::class);
    $store->shouldReceive('table')->andReturnSelf();
    $store->shouldReceive('similaritySearch')
        ->with([0.1, 0.2], 3, 0.5)
        ->andReturn(collect([
            ['id' => '1', 'score' => 0.9, 'metadata' => [], 'content' => 'result'],
        ]));

    app()->instance(VectorStoreContract::class, $store);

    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use HasVectorSearch;

        protected $table = 'documents';
    };

    $results = $model::semanticSearch([0.1, 0.2], 3, 0.5);

    expect($results)->toHaveCount(1)
        ->and($results->first()['content'])->toBe('result');
});

test('hybridSearch delegates to HybridSearch', function () {
    $hybrid = Mockery::mock(HybridSearch::class);
    $hybrid->shouldReceive('search')
        ->with('documents', 'test query', [0.1], 0.7, 0.3, 5)
        ->andReturn(collect([
            ['id' => '1', 'score' => 0.95, 'metadata' => [], 'content' => 'hybrid result'],
        ]));

    app()->instance(HybridSearch::class, $hybrid);

    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use HasVectorSearch;

        protected $table = 'documents';
    };

    $results = $model::hybridSearch('test query', [0.1], 0.7, 0.3, 5);

    expect($results)->toHaveCount(1)
        ->and($results->first()['content'])->toBe('hybrid result');
});

test('nearestTo returns empty for model without embedding', function () {
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use HasVectorSearch;

        protected $table = 'documents';

        protected $attributes = ['embedding' => null];
    };

    expect($model->nearestTo(5))->toBeEmpty();
});

test('nearestTo excludes self from results', function () {
    $store = Mockery::mock(VectorStoreContract::class);
    $store->shouldReceive('table')->andReturnSelf();
    $store->shouldReceive('similaritySearch')->andReturn(collect([
        ['id' => '1', 'score' => 1.0, 'metadata' => [], 'content' => 'self'],
        ['id' => '2', 'score' => 0.9, 'metadata' => [], 'content' => 'neighbour'],
        ['id' => '3', 'score' => 0.8, 'metadata' => [], 'content' => 'another'],
    ]));

    app()->instance(VectorStoreContract::class, $store);

    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use HasVectorSearch;

        protected $table = 'documents';

        protected $primaryKey = 'id';

        public $incrementing = false;

        protected $keyType = 'string';
    };
    $model->id = '1';
    $model->embedding = '[0.1,0.2,0.3]';

    $results = $model->nearestTo(2);

    expect($results)->toHaveCount(2);
    $ids = $results->pluck('id')->toArray();
    expect($ids)->not->toContain('1')
        ->and($ids)->toContain('2', '3');
});
