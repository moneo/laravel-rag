<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Chunking\Strategies;

use Moneo\LaravelRag\Support\PrismRetryHandler;

class SemanticChunker implements ChunkerContract
{
    /**
     * Split text into semantically coherent chunks using embedding similarity.
     *
     * Embeds each sentence, then splits where adjacent sentence similarity
     * drops below the threshold.
     *
     * @param  string  $text  The text to chunk
     * @param  array{threshold?: float, size?: int}  $options
     * @return array<int, string>
     */
    public function chunk(string $text, array $options = []): array
    {
        $threshold = $options['threshold'] ?? 0.85;
        $maxSize = $options['size'] ?? (int) config('rag.ingest.chunk_size', 500);

        $text = trim($text);

        if ($text === '' || $text === '0') {
            return [];
        }

        $sentences = preg_split(
            '/(?<=[.!?])\s+/',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (count($sentences) <= 1) {
            return [$text];
        }

        $embeddings = $this->embedSentences($sentences);

        // Find split points where similarity drops below threshold
        $splitPoints = [];
        for ($i = 0; $i < count($embeddings) - 1; $i++) {
            $similarity = $this->cosineSimilarity($embeddings[$i], $embeddings[$i + 1]);

            if ($similarity < $threshold) {
                $splitPoints[] = $i + 1;
            }
        }

        // Build chunks from split points
        $chunks = [];
        $start = 0;

        foreach ($splitPoints as $splitPoint) {
            $chunkSentences = array_slice($sentences, $start, $splitPoint - $start);
            $chunk = implode(' ', $chunkSentences);

            // Respect max size — further split if needed
            if (mb_strlen($chunk) > $maxSize) {
                $subChunks = $this->splitBySize($chunkSentences, $maxSize);
                array_push($chunks, ...$subChunks);
            } else {
                $chunks[] = trim($chunk);
            }

            $start = $splitPoint;
        }

        // Remaining sentences
        $remaining = array_slice($sentences, $start);
        if ($remaining !== []) {
            $chunk = implode(' ', $remaining);

            if (mb_strlen($chunk) > $maxSize) {
                $subChunks = $this->splitBySize($remaining, $maxSize);
                array_push($chunks, ...$subChunks);
            } else {
                $chunks[] = trim($chunk);
            }
        }

        return array_values(array_filter($chunks, fn (string $c): bool => !in_array(trim($c), ['', '0'], true)));
    }

    /**
     * Embed all sentences via Prism.
     *
     * @param  array<int, string>  $sentences
     * @return array<int, array<int, float>>
     */
    protected function embedSentences(array $sentences): array
    {
        $config = config('rag.embedding');
        $handler = app(PrismRetryHandler::class);
        $embeddings = [];

        foreach ($sentences as $sentence) {
            $embeddings[] = $handler->embed(
                $sentence,
                $config['driver'],
                $config['model'],
                (int) $config['dimensions'],
            );
        }

        return $embeddings;
    }

    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0, $count = count($a); $i < $count; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $dotProduct / $denominator;
    }

    /**
     * Split sentences into chunks respecting max size.
     *
     * @param  array<int, string>  $sentences
     * @return array<int, string>
     */
    protected function splitBySize(array $sentences, int $maxSize): array
    {
        $chunks = [];
        $currentChunk = '';

        foreach ($sentences as $sentence) {
            if ($currentChunk !== '' && mb_strlen($currentChunk) + mb_strlen($sentence) + 1 > $maxSize) {
                $chunks[] = trim($currentChunk);
                $currentChunk = '';
            }

            $currentChunk .= ($currentChunk !== '' ? ' ' : '').$sentence;
        }

        if (!in_array(trim($currentChunk), ['', '0'], true)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }
}
