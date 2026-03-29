<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Livewire;

use Livewire\Component;
use Moneo\LaravelRag\Memory\RagThread;

class RagChat extends Component
{
    public string $model = '';

    public string $systemPrompt = '';

    public ?int $threadId = null;

    public string $placeholder = 'Ask anything...';

    public int $limit = 5;

    public string $question = '';

    public bool $showSources = false;

    /** @var array<int, array{role: string, content: string, sources?: array}> */
    public array $messages = [];

    public bool $isLoading = false;

    public function mount(
        string $model = '',
        string $systemPrompt = '',
        ?int $threadId = null,
        string $placeholder = 'Ask anything...',
        int $limit = 5,
    ): void {
        $this->model = $model;
        $this->systemPrompt = $systemPrompt;
        $this->threadId = $threadId;
        $this->placeholder = $placeholder;
        $this->limit = $limit;

        // Load existing thread messages
        if ($this->threadId) {
            $thread = RagThread::find($this->threadId);
            if ($thread) {
                $this->messages = $thread->messages()
                    ->get()
                    ->map(fn ($msg): array => [
                        'role' => $msg->role,
                        'content' => $msg->content,
                    ])
                    ->toArray();
            }
        }
    }

    /**
     * Send a message and get a RAG response.
     */
    public function send(): void
    {
        $question = trim($this->question);

        if ($question === '' || $question === '0') {
            return;
        }

        $this->isLoading = true;
        $this->messages[] = ['role' => 'user', 'content' => $question];
        $this->question = '';

        try {
            $thread = $this->resolveThread();
            $result = $thread->ask($question);

            $message = ['role' => 'assistant', 'content' => $result->answer];

            if ($this->showSources) {
                $message['sources'] = $result->sources()->toArray();
            }

            $this->messages[] = $message;
        } catch (\Throwable $e) {
            \Moneo\LaravelRag\Support\RagLogger::error('livewire.chat', $e, [
                'thread_id' => $this->threadId,
                'model' => $this->model,
            ]);
            $this->messages[] = [
                'role' => 'assistant',
                'content' => 'An error occurred: '.$e->getMessage(),
            ];
        } finally {
            $this->isLoading = false;
        }
    }

    /**
     * Toggle sources visibility.
     */
    public function toggleSources(): void
    {
        $this->showSources = ! $this->showSources;
    }

    /**
     * Clear the conversation.
     */
    public function clearChat(): void
    {
        $this->messages = [];
        $this->threadId = null;
    }

    public function render(): \Illuminate\View\View
    {
        return view('rag::livewire.rag-chat');
    }

    /**
     * Resolve or create a thread.
     */
    protected function resolveThread(): RagThread
    {
        if ($this->threadId) {
            $thread = RagThread::find($this->threadId);
            if ($thread) {
                return $thread;
            }
        }

        $thread = RagThread::create([
            'model' => $this->model,
            'title' => mb_substr($this->messages[0]['content'] ?? 'New Chat', 0, 100),
        ]);

        $this->threadId = $thread->id;

        return $thread;
    }
}
