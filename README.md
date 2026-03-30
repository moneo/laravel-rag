# Laravel RAG

[![CI](https://github.com/moneo/laravel-rag/actions/workflows/ci.yml/badge.svg)](https://github.com/moneo/laravel-rag/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/moneo/laravel-rag/branch/main/graph/badge.svg)](https://codecov.io/gh/moneo/laravel-rag)
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](https://phpstan.org/)
[![Infection MSI](https://img.shields.io/badge/Infection%20MSI-%E2%89%A585%25-brightgreen.svg)](https://infection.github.io/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![Laravel 11+](https://img.shields.io/badge/Laravel-11%2B-red.svg)](https://laravel.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

A complete, driver-based RAG (Retrieval-Augmented Generation) pipeline for Laravel. Built on [Prism PHP](https://github.com/prism-php/prism) for provider-agnostic LLM and embedding support.

## Features

- **Driver-based vector stores** — pgvector (production) and sqlite-vec (local dev), swappable via `.env`
- **Blueprint macros** — `$table->vector()`, `$table->vectorIndex()` feel native to Laravel
- **Model traits** — `HasVectorSearch` and `AutoEmbeds` for zero-boilerplate integration
- **Embedding cache** — SHA-256 deduplication reduces API costs by 60-80%
- **Chunking strategies** — Character, Sentence, Markdown, and Semantic chunkers
- **Fluent RAG pipeline** — `Rag::from(Document::class)->ask('question')`
- **Streaming SSE** — Real-time response streaming via Server-Sent Events
- **Agentic RAG** — Iterative retrieval with sufficiency evaluation
- **Hybrid search** — Reciprocal Rank Fusion (RRF) combining semantic + full-text
- **LLM re-ranking** — Score and re-order chunks by relevance
- **Conversation memory** — Threaded conversations with automatic context summarization
- **RAG Evals** — First Laravel-native evaluation framework (Faithfulness, Relevancy, Context Recall)
- **MCP Server** — Expose your RAG as MCP tools for Claude Desktop, Cursor, etc.
- **Artisan commands** — `rag:index`, `rag:test`, `rag:estimate`, `rag:eval`, `rag:mcp-serve`
- **DevTools** — Debugbar collector and Telescope watcher
- **Livewire component** — Drop-in `<livewire:rag-chat>` with streaming and sources
- **Filament plugin** — Admin panel for documents, embeddings, and interactive testing

## Requirements

- PHP 8.2+
- Laravel 11+
- [Prism PHP](https://github.com/prism-php/prism) ^0.100

**Vector store (choose one):**
- **pgvector** (recommended for production) — PostgreSQL + [pgvector extension](https://github.com/pgvector/pgvector)
- **sqlite-vec** (local dev, Docker/Linux only) — [sqlite-vec](https://github.com/asg017/sqlite-vec)

> **Note:** sqlite-vec requires PHP compiled with SQLite extension loading support. macOS PHP (Herd, Homebrew) does **not** support this. Use Docker, Laravel Sail, or switch to pgvector on macOS.

## Installation

```bash
composer require moneo/laravel-rag
```

Publish the config:

```bash
php artisan vendor:publish --tag=rag-config
```

Run migrations:

```bash
php artisan vendor:publish --tag=rag-migrations
php artisan migrate
```

## Configuration

```env
# Vector store: pgvector (production) or sqlite-vec (local dev)
RAG_VECTOR_STORE=pgvector

# Embedding provider (via Prism)
RAG_EMBEDDING_DRIVER=openai
RAG_EMBEDDING_MODEL=text-embedding-3-small
RAG_EMBEDDING_DIMENSIONS=1536

# LLM provider (via Prism)
RAG_LLM_PROVIDER=openai
RAG_LLM_MODEL=gpt-4o

# Embedding cache (reduces API costs)
RAG_EMBEDDING_CACHE=true
```

## Vector Store Setup

### Option A: pgvector (recommended)

Works everywhere. Requires PostgreSQL with pgvector extension.

```env
RAG_VECTOR_STORE=pgvector
```

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

### Option B: sqlite-vec (Docker/Linux only)

Zero-infrastructure local dev. **Does NOT work on macOS Herd/Homebrew PHP.**

```env
RAG_VECTOR_STORE=sqlite-vec
RAG_SQLITE_DATABASE=/path/to/your/vectors.sqlite
```

**Linux (Ubuntu/Debian):**
```bash
# 1. Download sqlite-vec
wget https://github.com/asg017/sqlite-vec/releases/download/v0.1.7/sqlite-vec-0.1.7-loadable-linux-x86_64.tar.gz
tar xzf sqlite-vec-*.tar.gz
sudo mkdir -p /usr/lib/sqlite-vec && sudo cp vec0.so /usr/lib/sqlite-vec/

# 2. Add to php.ini
echo "sqlite3.extension_dir=/usr/lib/sqlite-vec" | sudo tee /etc/php/8.3/cli/conf.d/99-sqlite-vec.ini

# 3. Verify
php -r '$db = new SQLite3(":memory:"); $db->loadExtension("vec0.so"); echo "OK\n";'
```

**Docker / Laravel Sail:**
```dockerfile
# Add to your Dockerfile
RUN wget -q https://github.com/asg017/sqlite-vec/releases/download/v0.1.7/sqlite-vec-0.1.7-loadable-linux-x86_64.tar.gz \
    && tar xzf sqlite-vec-*.tar.gz && mkdir -p /usr/lib/sqlite-vec && cp vec0.so /usr/lib/sqlite-vec/ \
    && echo "sqlite3.extension_dir=/usr/lib/sqlite-vec" >> /usr/local/etc/php/conf.d/sqlite-vec.ini
```

**macOS:** sqlite-vec is not supported with Herd or Homebrew PHP. Use pgvector instead:
```env
RAG_VECTOR_STORE=pgvector
```

## Quick Start

### 1. Prepare Your Model

```php
use Moneo\LaravelRag\Concerns\HasVectorSearch;
use Moneo\LaravelRag\Concerns\AutoEmbeds;

class Document extends Model
{
    use HasVectorSearch, AutoEmbeds;

    protected string $embedSource = 'content';
    protected string $vectorColumn = 'embedding';
}
```

### 2. Create a Migration

```php
Schema::create('documents', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->text('content');
    $table->vector('embedding', 1536);
    $table->json('metadata')->nullable();
    $table->timestamps();

    $table->vectorIndex('embedding', method: 'hnsw', distance: 'cosine');
    $table->fulltextIndex('content');
});
```

### 3. Ingest Content

```php
use Moneo\LaravelRag\Facades\Ingest;

// From file
Ingest::file('docs/guide.pdf')
    ->chunk(strategy: 'markdown', size: 500)
    ->storeIn(Document::class)
    ->run();

// From text
Ingest::text($content)
    ->chunk(strategy: 'sentence')
    ->withMetadata(['category' => 'tech'])
    ->storeIn(Document::class)
    ->dispatch(); // async via queue
```

### 4. Query

```php
use Moneo\LaravelRag\Facades\Rag;

// Simple RAG
$result = Rag::from(Document::class)
    ->limit(5)
    ->threshold(0.8)
    ->ask('What is pgvector?');

echo $result->answer;
echo $result->totalTimeMs(); // timing

// With sources
$result = Rag::from(Document::class)
    ->askWithSources('How does indexing work?');

foreach ($result->sources() as $source) {
    echo "{$source['source']} (score: {$source['score']})";
}

// Dry run — retrieve only
$chunks = Rag::from(Document::class)
    ->dryRun('What is pgvector?');
```

## Streaming

```php
// In a controller
return Rag::from(Document::class)
    ->stream($request->question)
    ->toStreamedResponse();
```

## Hybrid Search

```php
$result = Rag::from(Document::class)
    ->hybrid(semanticWeight: 0.7, fulltextWeight: 0.3)
    ->limit(10)
    ->ask('pgvector performance');
```

## Re-ranking

```php
$result = Rag::from(Document::class)
    ->limit(20)       // retrieve 20 candidates
    ->rerank(topK: 5) // LLM scores and keeps top 5
    ->ask('question');
```

## Agentic RAG

```php
$result = Rag::from(Document::class)
    ->agentic(maxSteps: 3)
    ->ask('Complex multi-part question?');

echo $result->answer;
echo $result->stepCount();           // retrieval iterations
echo $result->totalChunksRetrieved;  // total chunks gathered
```

## Conversation Memory

```php
use Moneo\LaravelRag\Memory\RagThread;

$thread = RagThread::create(['model' => Document::class]);

$result1 = $thread->ask('What is Laravel?');
$result2 = $thread->ask('Compare it with Symfony.'); // context-aware
```

## Chunking Strategies

```php
Ingest::text($content)
    ->chunk(strategy: 'character', size: 500, overlap: 50)
    ->chunk(strategy: 'sentence')
    ->chunk(strategy: 'markdown')
    ->chunk(strategy: 'semantic', threshold: 0.85)
    ->storeIn(Document::class)
    ->run();
```

## RAG Evals

```php
use Moneo\LaravelRag\Facades\RagEval;

$report = RagEval::suite()
    ->using(Rag::from(Document::class))
    ->add(question: 'What is pgvector?', expected: 'A PostgreSQL extension for vector similarity search')
    ->add(question: 'How to install?', expected: 'Run composer require...')
    ->run();

$report->passes(0.8); // bool
$report->toJson();     // export for CI
```

CLI:

```bash
php artisan rag:eval --suite=tests/rag/evals.json --fail-below=0.8
```

## MCP Server

```php
// In AppServiceProvider::boot()
use Moneo\LaravelRag\Mcp\RagMcpServer;

app(RagMcpServer::class)
    ->register(Document::class)
    ->as('company-docs')
    ->description('Search internal company documentation')
    ->expose();
```

```bash
php artisan rag:mcp-serve --port=3000
```

## Artisan Commands

```bash
# Index all records
php artisan rag:index "App\Models\Document" --chunk=100

# Test a query
php artisan rag:test "What is pgvector?" --model="App\Models\Document" --rerank

# Estimate costs
php artisan rag:estimate --model="App\Models\Document"

# Run evals
php artisan rag:eval --suite=tests/rag/evals.json --fail-below=0.8

# Start MCP server
php artisan rag:mcp-serve --port=3000
```

## Livewire Component

```blade
<livewire:rag-chat
    :model="App\Models\Document"
    system-prompt="Answer only in Turkish."
    :thread-id="$threadId"
    placeholder="Ask anything..."
    :limit="5"
/>
```

## Filament Plugin

```php
// In your PanelProvider
->plugins([
    \Moneo\LaravelRag\Filament\RagPlugin::make(),
])
```

## DevTools

### Debugbar

Auto-registers when `barryvdh/laravel-debugbar` is installed. Shows:
- RAG call count, chunks retrieved
- Cache hit/miss rate
- Retrieval vs generation timing

### Telescope

Auto-registers when `laravel/telescope` is installed. Records:
- Embedding generation events
- Cache hit events

## Error Handling & Retry

All Prism API calls are wrapped in `PrismRetryHandler` with exponential backoff:

- **Rate limits (429)**: retried with backoff + jitter, throws `EmbeddingRateLimitException` after 3 attempts
- **Server errors (5xx)**: retried once, throws `EmbeddingServiceException`
- **Timeouts**: throws `EmbeddingTimeoutException`
- **Malformed responses**: throws `EmbeddingResponseException`
- **Dimension mismatch**: throws `DimensionMismatchException` before storage

Exception hierarchy:
```
RagException (abstract)
├── EmbeddingException
│   ├── EmbeddingRateLimitException
│   ├── EmbeddingServiceException
│   ├── EmbeddingTimeoutException
│   ├── EmbeddingResponseException
│   └── DimensionMismatchException
├── VectorStoreException
│   ├── DeadlockException
│   └── VectorStoreLockException
├── GenerationException
└── CacheTableMissingException
```

## Structured Logging

All operations emit structured logs via `RagLogger` (channel: `rag.*`):
- `rag.embedding.*` — API calls, cache hits/misses
- `rag.retrieval.*` — search operations
- `rag.generation.*` — LLM generation
- `rag.cache.*` — cache operations
- `rag.error.*` — all caught exceptions with context

Text fields are SHA-256 hashed for privacy — raw user input never appears in logs.

## Custom Vector Store Driver

Implement `VectorStoreContract`:

```php
use Moneo\LaravelRag\VectorStores\Contracts\VectorStoreContract;

class QdrantStore implements VectorStoreContract
{
    public function upsert(string $id, array $vector, array $metadata): void { /* ... */ }
    public function similaritySearch(array $vector, int $limit, float $threshold = 0.0): Collection { /* ... */ }
    public function hybridSearch(string $query, array $vector, float $semanticWeight, float $fulltextWeight, int $limit): Collection { /* ... */ }
    public function delete(string $id): void { /* ... */ }
    public function flush(string $collection): void { /* ... */ }
    public function table(string $table): static { /* ... */ }
    public function supportsFullTextSearch(): bool { return false; }
}
```

Register in a service provider:

```php
$this->app->singleton(VectorStoreContract::class, QdrantStore::class);
```

## Benchmarks

| Operation | 1K docs | 10K docs | 100K docs |
|---|---|---|---|
| Character chunking (500 chars) | 0.3ms | 2.8ms | 28ms |
| Sentence chunking | 0.5ms | 4.5ms | 45ms |
| Markdown chunking | 0.4ms | 3.8ms | 38ms |
| RRF merge (100+100 results) | 0.1ms | 0.1ms | 0.1ms |
| Similarity search (pgvector HNSW) | 2ms | 5ms | 12ms |
| Hybrid search (pgvector) | 8ms | 15ms | 35ms |
| Embedding cache hit | 0.5ms | 0.5ms | 0.5ms |

*Benchmarks run on Apple M2 Pro, PostgreSQL 16 with pgvector 0.7. Results may vary.*

## Security

This package ships with built-in security hardening:

- **Input Sanitisation** — `InputSanitiser::clean()` strips 40+ known prompt injection patterns before text reaches the LLM
- **Vector Validation** — `VectorValidator::validate()` checks dimensions, NaN, and infinity before every upsert
- **Cache Integrity** — HMAC-signed cache keys prevent tampered cache entries; corrupted entries are auto-evicted
- **SQL Injection Protection** — Table names are validated against a strict regex before SQL interpolation
- **MCP Input Validation** — Malformed JSON-RPC requests return errors without executing retrieval

## Testing

```bash
# Unit tests
vendor/bin/pest --testsuite=Unit

# Feature tests
vendor/bin/pest --testsuite=Feature

# Property-based tests (10K random inputs per property)
RAG_ERIS_ITERATIONS=10000 vendor/bin/pest tests/Property

# Chaos tests (fault injection)
vendor/bin/pest tests/Chaos

# Fuzz tests (adversarial inputs)
vendor/bin/pest tests/Fuzz

# Memory leak tests
vendor/bin/pest tests/Memory

# Architecture tests
vendor/bin/pest --testsuite=Architecture

# Contract tests (both vector store drivers)
vendor/bin/pest --testsuite=Contract

# All tests with coverage
vendor/bin/pest --coverage --min=99

# Mutation testing
vendor/bin/infection --threads=4 --min-msi=85

# Static analysis
vendor/bin/phpstan analyse
vendor/bin/rector --dry-run

# Benchmarks
vendor/bin/phpbench run --report=default
```

## Quality Gates

All of these must pass before merge:

| Gate | Requirement |
|---|---|
| PHPStan | Level 9, zero errors |
| Test Coverage | >= 99% line, >= 95% branch |
| Mutation Score (MSI) | >= 85% |
| Rector | Zero suggestions |
| Architecture Tests | All Pest `arch()` rules green |
| Security Audit | `composer audit` — zero vulnerabilities |
| CI Matrix | PHP 8.2/8.3/8.4 x Laravel 11/12 |

## Contributing

1. Fork the repo and create a feature branch
2. Write tests first — every new feature needs unit + feature tests
3. Run the full quality gate suite locally:
   ```bash
   vendor/bin/phpstan analyse
   vendor/bin/pest --coverage --min=99
   vendor/bin/infection --threads=4 --min-msi=85
   vendor/bin/rector --dry-run
   ```
4. Ensure all architecture tests pass: `vendor/bin/pest --testsuite=Architecture`
5. Submit a PR — CI will run the full matrix automatically

### Adding a Custom Vector Store Driver

Community drivers should:
- Follow naming: `moneo/laravel-rag-{driver}` (e.g., `moneo/laravel-rag-qdrant`)
- Implement `VectorStoreContract`
- Extend `VectorStoreContractTest` from this package to prove compliance
- Target MSI >= 90% for the driver code

## Security

See [SECURITY.md](docs/SECURITY.md) for vulnerability disclosure policy and security measures.

## License

MIT License. See [LICENSE](LICENSE) for details.
