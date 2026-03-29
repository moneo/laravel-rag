<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Memory;

use Moneo\LaravelRag\Support\PrismRetryHandler;

class ContextWindowManager
{
    protected readonly int $maxTokens;

    protected readonly float $summaryThreshold;

    public function __construct()
    {
        $this->maxTokens = (int) config('rag.memory.max_tokens', 4000);
        $this->summaryThreshold = (float) config('rag.memory.summary_threshold', 0.8);
    }

    /**
     * Build conversation context for a thread, summarizing old messages if needed.
     *
     * @param  RagThread  $thread  The conversation thread
     * @return string  The formatted conversation context
     */
    public function buildContext(RagThread $thread): string
    {
        $messages = $thread->messages()->get();

        if ($messages->isEmpty()) {
            return '';
        }

        $totalTokens = $messages->sum('tokens');
        $threshold = (int) ($this->maxTokens * $this->summaryThreshold);

        // If within budget, return all messages
        if ($totalTokens <= $threshold) {
            return $this->formatMessages($messages);
        }

        // Summarize older messages, keep recent ones
        return $this->summarizeAndTruncate($messages, $threshold);
    }

    /**
     * Format messages into conversation text.
     *
     * @param  \Illuminate\Support\Collection<int, ThreadMessage>  $messages
     */
    protected function formatMessages($messages): string
    {
        return $messages->map(function (ThreadMessage $msg): string {
            $role = ucfirst($msg->role);

            return "{$role}: {$msg->content}";
        })->implode("\n\n");
    }

    /**
     * Summarize older messages and keep recent ones within token budget.
     *
     * @param  \Illuminate\Support\Collection<int, ThreadMessage>  $messages
     */
    protected function summarizeAndTruncate($messages, int $tokenBudget): string
    {
        // Keep the most recent messages that fit in half the budget
        $recentBudget = (int) ($tokenBudget * 0.6);
        $recent = collect();
        $recentTokens = 0;

        foreach ($messages->reverse() as $message) {
            if ($recentTokens + $message->tokens > $recentBudget) {
                break;
            }
            $recent->prepend($message);
            $recentTokens += $message->tokens;
        }

        // Summarize everything before the recent messages
        $olderMessages = $messages->take($messages->count() - $recent->count());

        if ($olderMessages->isEmpty()) {
            return $this->formatMessages($recent);
        }

        $olderText = $this->formatMessages($olderMessages);
        $summary = $this->summarize($olderText);

        $parts = ["[Summary of earlier conversation]\n{$summary}"];

        if ($recent->isNotEmpty()) {
            $parts[] = "[Recent messages]\n".$this->formatMessages($recent);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Generate a summary of conversation text using the LLM.
     */
    protected function summarize(string $text): string
    {
        $provider = config('rag.llm.provider');
        $model = config('rag.llm.model');

        return app(PrismRetryHandler::class)->generate($provider, $model, 'Summarize the following conversation concisely, preserving key facts, questions, and answers. Keep it under 200 words.', $text);
    }
}
