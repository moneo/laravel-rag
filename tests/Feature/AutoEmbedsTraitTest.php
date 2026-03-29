<?php

declare(strict_types=1);

use Moneo\LaravelRag\Cache\EmbeddingCache;
use Moneo\LaravelRag\Concerns\AutoEmbeds;
use Moneo\LaravelRag\Support\PrismRetryHandler;

test('generateAndStoreEmbedding calls PrismRetryHandler and sets attribute', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => false]);

    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldReceive('embed')->once()->andReturn([0.1, 0.2, 0.3]);
    app()->instance(PrismRetryHandler::class, $prism);
    app()->instance(EmbeddingCache::class, new EmbeddingCache(enabled: false));

    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use AutoEmbeds;

        protected $fillable = ['content', 'embedding'];
    };

    $model->content = 'Hello world';
    $model->generateAndStoreEmbedding();

    expect($model->getAttribute('embedding'))->toBe([0.1, 0.2, 0.3]);
});

test('generateAndStoreEmbedding skips empty text', function () {
    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldNotReceive('embed');
    app()->instance(PrismRetryHandler::class, $prism);
    app()->instance(EmbeddingCache::class, new EmbeddingCache(enabled: false));

    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use AutoEmbeds;

        protected $fillable = ['content', 'embedding'];
    };

    $model->content = '   ';
    $model->generateAndStoreEmbedding();

    expect($model->getAttribute('embedding'))->toBeNull();
});

test('generateAndStoreEmbedding uses cache on hit', function () {
    config(['app.key' => 'test-key', 'rag.embedding.cache' => true]);

    $cache = Mockery::mock(EmbeddingCache::class)->makePartial();
    $ref = new ReflectionProperty(EmbeddingCache::class, 'enabled');
    $ref->setValue($cache, true);
    $cache->shouldReceive('get')->andReturn([0.5, 0.6, 0.7]);

    app()->instance(EmbeddingCache::class, $cache);

    $prism = Mockery::mock(PrismRetryHandler::class);
    $prism->shouldNotReceive('embed');
    app()->instance(PrismRetryHandler::class, $prism);

    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use AutoEmbeds;

        protected $fillable = ['content', 'embedding'];

        protected bool $embedCache = true;
    };

    $model->content = 'cached text';
    $model->generateAndStoreEmbedding();

    expect($model->getAttribute('embedding'))->toBe([0.5, 0.6, 0.7]);
});

test('shouldGenerateEmbedding with multi-column source', function () {
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use AutoEmbeds;

        protected $fillable = ['title', 'body', 'embedding'];

        protected array $embedSource = ['title', 'body'];
    };

    $model->title = 'New title';

    expect($model->shouldGenerateEmbedding())->toBeTrue();
});

test('getEmbedSourceText joins multiple columns', function () {
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use AutoEmbeds;

        protected $fillable = ['title', 'body'];

        protected array $embedSource = ['title', 'body'];
    };

    $model->title = 'Hello';
    $model->body = 'World';

    expect($model->getEmbedSourceText())->toBe("Hello\n\nWorld");
});
