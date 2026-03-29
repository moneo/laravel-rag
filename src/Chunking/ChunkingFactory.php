<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Chunking;

use InvalidArgumentException;
use Moneo\LaravelRag\Chunking\Strategies\CharacterChunker;
use Moneo\LaravelRag\Chunking\Strategies\ChunkerContract;
use Moneo\LaravelRag\Chunking\Strategies\MarkdownChunker;
use Moneo\LaravelRag\Chunking\Strategies\SemanticChunker;
use Moneo\LaravelRag\Chunking\Strategies\SentenceChunker;

class ChunkingFactory
{
    /**
     * @var array<string, class-string<ChunkerContract>>
     */
    protected array $strategies = [
        'character' => CharacterChunker::class,
        'sentence' => SentenceChunker::class,
        'markdown' => MarkdownChunker::class,
        'semantic' => SemanticChunker::class,
    ];

    /**
     * Create a chunker instance by strategy name.
     *
     * @param  string  $strategy  The strategy name
     *
     * @throws InvalidArgumentException
     */
    public function make(string $strategy): ChunkerContract
    {
        if (! isset($this->strategies[$strategy])) {
            throw new InvalidArgumentException("Unknown chunking strategy: {$strategy}. Available: ".implode(', ', array_keys($this->strategies)));
        }

        return new $this->strategies[$strategy];
    }

    /**
     * Register a custom chunking strategy.
     *
     * @param  string  $name  The strategy name
     * @param  class-string<ChunkerContract>  $class  The chunker class
     */
    public function extend(string $name, string $class): void
    {
        $this->strategies[$name] = $class;
    }

    /**
     * Get all available strategy names.
     *
     * @return array<int, string>
     */
    public function available(): array
    {
        return array_keys($this->strategies);
    }
}
