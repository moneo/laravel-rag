<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Chunking\Strategies;

class MarkdownChunker implements ChunkerContract
{
    /**
     * Split markdown text into chunks by headers and sections.
     *
     * @param  string  $text  The markdown text
     * @param  array{size?: int, overlap?: int}  $options
     * @return array<int, string>
     */
    public function chunk(string $text, array $options = []): array
    {
        $maxSize = $options['size'] ?? (int) config('rag.ingest.chunk_size', 500);

        $text = trim($text);

        if ($text === '' || $text === '0') {
            return [];
        }

        // Split by markdown headers (## or ###, etc.)
        $sections = preg_split('/(?=^#{1,6}\s)/m', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($sections)) {
            return [$text];
        }

        $chunks = [];

        foreach ($sections as $section) {
            $section = trim($section);

            if ($section === '' || $section === '0') {
                continue;
            }

            if (mb_strlen($section) <= $maxSize) {
                $chunks[] = $section;
            } else {
                // Section too large — further split by paragraphs
                $paragraphs = preg_split('/\n\s*\n/', $section, -1, PREG_SPLIT_NO_EMPTY);
                $currentChunk = '';

                foreach ($paragraphs as $paragraph) {
                    $paragraph = trim($paragraph);

                    if ($paragraph === '' || $paragraph === '0') {
                        continue;
                    }

                    if ($currentChunk !== '' && mb_strlen($currentChunk) + mb_strlen($paragraph) + 2 > $maxSize) {
                        $chunks[] = trim($currentChunk);
                        $currentChunk = '';
                    }

                    $currentChunk .= ($currentChunk !== '' ? "\n\n" : '').$paragraph;
                }

                if (!in_array(trim($currentChunk), ['', '0'], true)) {
                    $chunks[] = trim($currentChunk);
                }
            }
        }

        return $chunks;
    }
}
