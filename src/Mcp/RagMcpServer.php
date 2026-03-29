<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Mcp;

use Illuminate\Support\Collection;
use Moneo\LaravelRag\Pipeline\RagPipeline;

class RagMcpServer
{
    /** @var array<string, array{model: string, name: string, description: string, pipeline: RagPipeline}> */
    protected array $tools = [];

    /**
     * Register a model class as an MCP tool.
     *
     * @param  string  $modelClass  The Eloquent model class
     */
    public function register(string $modelClass): McpToolRegistrar
    {
        return new McpToolRegistrar($this, $modelClass);
    }

    /**
     * Add a tool definition (called by McpToolRegistrar).
     *
     * @param  string  $name  Tool name
     * @param  string  $description  Tool description
     * @param  string  $modelClass  The model class
     * @param  RagPipeline  $pipeline  The configured pipeline
     */
    public function addTool(string $name, string $description, string $modelClass, RagPipeline $pipeline): void
    {
        $this->tools[$name] = [
            'model' => $modelClass,
            'name' => $name,
            'description' => $description,
            'pipeline' => $pipeline,
        ];
    }

    /**
     * Handle an incoming JSON-RPC request.
     *
     * @param  array<string, mixed>  $request  The JSON-RPC request
     * @return array<string, mixed>  The JSON-RPC response
     */
    public function handleRequest(array $request): array
    {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        return match ($method) {
            'initialize' => $this->handleInitialize($id),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolCall($id, $params),
            default => $this->errorResponse($id, -32601, "Method not found: {$method}"),
        };
    }

    /**
     * Handle MCP initialize request.
     *
     * @return array<string, mixed>
     */
    protected function handleInitialize(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                ],
                'serverInfo' => [
                    'name' => 'laravel-rag',
                    'version' => '1.0.0',
                ],
            ],
        ];
    }

    /**
     * Handle MCP tools/list request.
     *
     * @return array<string, mixed>
     */
    protected function handleToolsList(mixed $id): array
    {
        $tools = [];

        foreach ($this->tools as $name => $tool) {
            $tools[] = [
                'name' => "{$name}_search",
                'description' => "Search: {$tool['description']}",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'The search query'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results', 'default' => 5],
                    ],
                    'required' => ['query'],
                ],
            ];

            $tools[] = [
                'name' => "{$name}_ask",
                'description' => "Ask: {$tool['description']}",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string', 'description' => 'The question to answer'],
                    ],
                    'required' => ['question'],
                ],
            ];
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $tools],
        ];
    }

    /**
     * Handle MCP tools/call request.
     *
     * @return array<string, mixed>
     */
    protected function handleToolCall(mixed $id, array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        // Parse tool name: {name}_search or {name}_ask
        foreach ($this->tools as $name => $tool) {
            if ($toolName === "{$name}_search") {
                return $this->handleSearch($id, $tool, $arguments);
            }

            if ($toolName === "{$name}_ask") {
                return $this->handleAsk($id, $tool, $arguments);
            }
        }

        return $this->errorResponse($id, -32602, "Unknown tool: {$toolName}");
    }

    /**
     * Handle a search tool call.
     *
     * @return array<string, mixed>
     */
    protected function handleSearch(mixed $id, array $tool, array $arguments): array
    {
        $query = $arguments['query'] ?? '';
        $limit = (int) ($arguments['limit'] ?? 5);

        $chunks = $tool['pipeline']->limit($limit)->dryRun($query);

        $content = $chunks->map(fn (array $chunk): array => [
            'type' => 'text',
            'text' => json_encode([
                'id' => $chunk['id'],
                'score' => $chunk['score'],
                'content' => $chunk['content'] ?? '',
                'metadata' => $chunk['metadata'] ?? [],
            ]),
        ])->toArray();

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['content' => $content],
        ];
    }

    /**
     * Handle an ask tool call.
     *
     * @return array<string, mixed>
     */
    protected function handleAsk(mixed $id, array $tool, array $arguments): array
    {
        $question = $arguments['question'] ?? '';

        $result = $tool['pipeline']->askWithSources($question);

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result->toArray()),
                    ],
                ],
            ],
        ];
    }

    /**
     * Build a JSON-RPC error response.
     *
     * @return array<string, mixed>
     */
    protected function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    /**
     * Get all registered tools.
     *
     * @return array<string, array{model: string, name: string, description: string, pipeline: RagPipeline}>
     */
    public function getTools(): array
    {
        return $this->tools;
    }
}
