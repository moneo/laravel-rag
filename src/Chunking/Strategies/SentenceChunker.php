<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Chunking\Strategies;

class SentenceChunker implements ChunkerContract
{
    /**
     * Split text into chunks at sentence boundaries.
     *
     * @param  string  $text  The text to chunk
     * @param  array{max_sentences?: int, size?: int}  $options
     * @return array<int, string>
     */
    public function chunk(string $text, array $options = []): array
    {
        $maxSize = $options['size'] ?? (int) config('rag.ingest.chunk_size', 500);
        $maxSentences = $options['max_sentences'] ?? 10;

        $text = trim($text);

        if ($text === '' || $text === '0') {
            return [];
        }

        // Split into sentences using common sentence-ending punctuation
        $sentences = preg_split(
            '/(?<=[.!?])\s+/',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (empty($sentences)) {
            return [$text];
        }

        $chunks = [];
        $currentChunk = '';
        $sentenceCount = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '' || $sentence === '0') {
                continue;
            }

            $wouldBeLength = mb_strlen($currentChunk) + mb_strlen($sentence) + 1;

            if ($currentChunk !== '' && ($wouldBeLength > $maxSize || $sentenceCount >= $maxSentences)) {
                $chunks[] = trim($currentChunk);
                $currentChunk = '';
                $sentenceCount = 0;
            }

            $currentChunk .= ($currentChunk !== '' ? ' ' : '').$sentence;
            $sentenceCount++;
        }

        if (!in_array(trim($currentChunk), ['', '0'], true)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }
}
