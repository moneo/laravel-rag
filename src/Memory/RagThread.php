<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Memory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Moneo\LaravelRag\Facades\Rag;
use Moneo\LaravelRag\Pipeline\RagResult;

/**
 * @property int $id
 * @property string|null $model
 * @property string|null $title
 * @property array<string, mixed>|null $metadata
 */
class RagThread extends Model
{
    protected $table = 'rag_threads';

    protected $fillable = [
        'model',
        'title',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Get all messages in this thread.
     *
     * @return HasMany<ThreadMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ThreadMessage::class, 'thread_id')->orderBy('created_at');
    }

    /**
     * Ask a question within this thread's context.
     *
     * @param  string  $question  The user's question
     */
    public function ask(string $question): RagResult
    {
        return DB::transaction(function () use ($question) {
            // Store user message
            $this->addMessage('user', $question);

            // Build conversation context
            $contextManager = app(ContextWindowManager::class);
            $conversationContext = $contextManager->buildContext($this);

            // Build pipeline with conversation context in system prompt
            $pipeline = Rag::from($this->model);

            if (! empty($conversationContext)) {
                $pipeline = $pipeline->systemPrompt(
                    "You are a helpful assistant. Use the conversation history and retrieved context to answer.\n\nConversation history:\n{$conversationContext}"
                );
            }

            $result = $pipeline->ask($question);

            // Store assistant response
            $this->addMessage('assistant', $result->answer);

            return $result;
        });
    }

    /**
     * Add a message to the thread.
     *
     * @param  string  $role  'user', 'assistant', or 'system'
     * @param  string  $content  The message content
     * @param  array<string, mixed>  $metadata  Optional metadata
     */
    public function addMessage(string $role, string $content, array $metadata = []): ThreadMessage
    {
        $tokens = $this->estimateTokens($content);

        return $this->messages()->create([
            'role' => $role,
            'content' => $content,
            'tokens' => $tokens,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get the total token count for this thread.
     */
    public function totalTokens(): int
    {
        return $this->messages()->sum('tokens');
    }

    /**
     * Estimate token count for a text (rough: ~4 chars per token).
     */
    protected function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
