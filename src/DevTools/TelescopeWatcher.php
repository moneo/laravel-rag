<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\DevTools;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Moneo\LaravelRag\Events\EmbeddingCacheHit;
use Moneo\LaravelRag\Events\EmbeddingGenerated;

class TelescopeWatcher extends ServiceProvider
{
    /**
     * Register the watcher.
     */
    public function register(): void
    {
        // Telescope watcher registers as a service provider
    }

    /**
     * Boot the watcher.
     */
    public function boot(): void
    {
        if (! class_exists(\Laravel\Telescope\Telescope::class)) {
            return;
        }

        $this->watchEmbeddings();
    }

    protected function watchEmbeddings(): void
    {
        Event::listen(EmbeddingGenerated::class, function (EmbeddingGenerated $event): void {
            if (! class_exists(\Laravel\Telescope\IncomingEntry::class)) {
                return;
            }

            \Laravel\Telescope\Telescope::recordEvent(\Laravel\Telescope\IncomingEntry::make([
                'type' => 'rag:embedding_generated',
                'model' => $event->model instanceof \Illuminate\Database\Eloquent\Model ? $event->model::class : self::class,
                'text_preview' => mb_substr($event->sourceText, 0, 200),
                'dimensions' => count($event->vector),
            ]));
        });

        Event::listen(EmbeddingCacheHit::class, function (EmbeddingCacheHit $event): void {
            if (! class_exists(\Laravel\Telescope\IncomingEntry::class)) {
                return;
            }

            \Laravel\Telescope\Telescope::recordEvent(\Laravel\Telescope\IncomingEntry::make([
                'type' => 'rag:embedding_cache_hit',
                'model' => $event->model instanceof \Illuminate\Database\Eloquent\Model ? $event->model::class : self::class,
                'text_preview' => mb_substr($event->sourceText, 0, 200),
            ]));
        });
    }
}
