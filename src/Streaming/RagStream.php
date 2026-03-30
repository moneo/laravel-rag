<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Streaming;

use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Moneo\LaravelRag\Support\RagLogger;
use Prism\Prism\Facades\Prism;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RagStream
{
    /**
     * @param  string  $question  The user's question
     * @param  string  $context  The built context from retrieved chunks
     * @param  Collection<int, array{id: string, score: float, metadata: array, content: string}>  $chunks  Retrieved chunks
     * @param  string|null  $systemPrompt  Custom system prompt
     * @param  string  $provider  LLM provider
     * @param  string  $model  LLM model
     */
    public function __construct(
        protected readonly string $question,
        protected readonly string $context,
        protected readonly Collection $chunks,
        protected readonly ?string $systemPrompt,
        protected readonly string $provider,
        protected readonly string $model,
    ) {}

    /**
     * Stream the response as Server-Sent Events.
     */
    public function toStreamedResponse(): StreamedResponse
    {
        return new StreamedResponse(function (): void {
            try {
                $systemPromptText = $this->systemPrompt ?? 'You are a helpful assistant. Answer the question based on the provided context.';
                $fullPrompt = "{$systemPromptText}\n\nContext:\n{$this->context}";

                // Intentional: Prism::stream() requires a persistent connection and cannot
                // be wrapped in PrismRetryHandler. Error handling is via the try-catch above.
                $stream = Prism::text()
                    ->using($this->provider, $this->model)
                    ->withSystemPrompt($fullPrompt)
                    ->withPrompt($this->question)
                    ->stream();

                // Send sources first
                echo "event: sources\n";
                echo 'data: '.json_encode($this->getSources())."\n\n";
                $this->flush();

                // Stream text chunks
                foreach ($stream as $chunk) {
                    echo "event: text\n";
                    echo 'data: '.json_encode(['text' => $chunk->text])."\n\n";
                    $this->flush();
                }

                // Signal completion
                echo "event: done\n";
                echo "data: {}\n\n";
                $this->flush();
            } catch (\Throwable $e) {
                RagLogger::error('stream', $e, [
                    'provider' => $this->provider,
                    'model' => $this->model,
                ]);

                echo "event: error\n";
                echo 'data: '.json_encode(['error' => $e->getMessage()])."\n\n";
                $this->flush();

                echo "event: done\n";
                echo "data: {}\n\n";
                $this->flush();
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Get source references from chunks.
     *
     * @return array<int, array{source: string, score: float, preview: string}>
     */
    protected function getSources(): array
    {
        return $this->chunks->map(fn (array $chunk): array => [
            'source' => $chunk['metadata']['source'] ?? 'Unknown',
            'score' => $chunk['score'],
            'preview' => mb_substr($chunk['content'] ?? '', 0, 200),
        ])->toArray();
    }

    /**
     * Flush output buffers.
     */
    protected function flush(): void
    {
        if (ob_get_level() !== 0) {
            ob_flush();
        }
        flush();
    }
}
