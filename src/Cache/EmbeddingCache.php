<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Cache;

use Illuminate\Support\Facades\DB;
use Moneo\LaravelRag\Security\CacheIntegrityException;
use Moneo\LaravelRag\Security\CacheIntegrityGuard;
use Moneo\LaravelRag\Support\RagLogger;

class EmbeddingCache
{
    protected int $hits = 0;

    protected int $misses = 0;

    /**
     * @param  bool  $enabled  Whether caching is enabled
     */
    public function __construct(
        public readonly bool $enabled,
    ) {}

    /**
     * Get a cached embedding vector by source text.
     *
     * @param  string  $text  The source text
     * @return array<int, float>|null  The cached vector or null on miss
     */
    public function get(string $text): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $hash = $this->hash($text);

        $row = DB::table('rag_embedding_cache')
            ->where('hash', $hash)
            ->first();

        if ($row === null) {
            $this->misses++;
            RagLogger::cache('miss', ['text' => $text]);

            return null;
        }

        $this->hits++;
        RagLogger::cache('hit', ['text' => $text]);

        $decoded = json_decode((string) $row->embedding, true);

        try {
            return CacheIntegrityGuard::validateCachedVector($decoded);
        } catch (CacheIntegrityException $e) {
            // Corrupted cache entry — evict and treat as miss
            RagLogger::error('cache.integrity', $e, ['text' => $text]);
            $this->forget($text);
            $this->hits--;
            $this->misses++;

            return null;
        }
    }

    /**
     * Store an embedding vector in the cache.
     *
     * @param  string  $text  The source text
     * @param  array<int, float>  $vector  The embedding vector
     */
    public function put(string $text, array $vector): void
    {
        if (! $this->enabled) {
            return;
        }

        $hash = $this->hash($text);

        DB::table('rag_embedding_cache')->updateOrInsert(
            ['hash' => $hash],
            [
                'embedding' => json_encode($vector),
                'text_preview' => mb_substr($text, 0, 200),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * Remove a cached embedding.
     *
     * @param  string  $text  The source text
     */
    public function forget(string $text): void
    {
        DB::table('rag_embedding_cache')
            ->where('hash', $this->hash($text))
            ->delete();
    }

    /**
     * Clear all cached embeddings.
     */
    public function flush(): void
    {
        DB::table('rag_embedding_cache')->truncate();
    }

    /**
     * Get the cache hit count for this request.
     */
    public function getHits(): int
    {
        return $this->hits;
    }

    /**
     * Get the cache miss count for this request.
     */
    public function getMisses(): int
    {
        return $this->misses;
    }

    /**
     * Get the cache hit rate (0.0 - 1.0).
     */
    public function getHitRate(): float
    {
        $total = $this->hits + $this->misses;

        return $total > 0 ? $this->hits / $total : 0.0;
    }

    /**
     * HMAC-SHA-256 hash of the source text, signed with the app key.
     */
    protected function hash(string $text): string
    {
        $appKey = config('app.key', 'laravel-rag-default-key');

        return CacheIntegrityGuard::signedHash($text, $appKey);
    }
}
