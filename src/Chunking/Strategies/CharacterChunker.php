<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Chunking\Strategies;

class CharacterChunker implements ChunkerContract
{
    /**
     * Split text into fixed-size character chunks with overlap.
     *
     * @param  string  $text  The text to chunk
     * @param  array{size?: int, overlap?: int}  $options
     * @return array<int, string>
     */
    public function chunk(string $text, array $options = []): array
    {
        $size = $options['size'] ?? (int) config('rag.ingest.chunk_size', 500);
        $overlap = $options['overlap'] ?? (int) config('rag.ingest.chunk_overlap', 50);

        if ($overlap >= $size) {
            throw new \InvalidArgumentException("Overlap ({$overlap}) must be less than chunk size ({$size}).");
        }

        $text = trim($text);

        if ($text === '' || $text === '0') {
            return [];
        }

        if (mb_strlen($text) <= $size) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $chunk = mb_substr($text, $offset, $size);
            $chunks[] = trim($chunk);

            $offset += $size - $overlap;
        }

        return array_filter($chunks, fn (string $chunk): bool => !in_array(trim($chunk), ['', '0'], true));
    }
}
