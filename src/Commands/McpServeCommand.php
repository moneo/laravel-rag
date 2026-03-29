<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Commands;

use Illuminate\Console\Command;
use Moneo\LaravelRag\Exceptions\RagException;
use Moneo\LaravelRag\Mcp\RagMcpServer;

class McpServeCommand extends Command
{
    protected $signature = 'rag:mcp-serve
        {--port=3000 : The port to listen on}
        {--host=127.0.0.1 : The host to bind to}';

    protected $description = 'Start the MCP server for RAG tools';

    public function handle(RagMcpServer $server): int
    {
        $port = (int) $this->option('port');
        $host = $this->option('host');

        $tools = $server->getTools();

        if ($tools === []) {
            $this->warn('No MCP tools registered. Register tools in your AppServiceProvider.');
            $this->line('Example: RagMcp::register(Document::class)->as("docs")->description("Search documents")->expose();');

            return self::FAILURE;
        }

        $this->info("Starting MCP server on {$host}:{$port}");
        $this->info('Registered tools:');

        foreach ($tools as $name => $tool) {
            $this->line("  - {$name}_search: Search {$tool['description']}");
            $this->line("  - {$name}_ask: Ask {$tool['description']}");
        }

        $this->newLine();

        // Start a simple HTTP server using PHP's built-in server
        // In production, this should be replaced with a proper HTTP server
        $this->info('Listening for JSON-RPC requests...');

        $socket = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

        if (! $socket) {
            $this->error("Failed to start server: {$errstr} ({$errno})");

            return self::FAILURE;
        }

        try {
            while ($conn = stream_socket_accept($socket, -1)) {
                $request = '';
                while ($line = fgets($conn)) {
                    $request .= $line;
                    if (trim($line) === '') {
                        // Read body based on Content-Length
                        if (preg_match('/Content-Length:\s*(\d+)/i', $request, $matches)) {
                            $bodyLength = (int) $matches[1];
                            $body = fread($conn, $bodyLength);
                            $jsonRequest = json_decode($body, true);

                            if ($jsonRequest) {
                                try {
                                    $response = $server->handleRequest($jsonRequest);
                                } catch (\Throwable $e) {
                                    $response = [
                                        'jsonrpc' => '2.0',
                                        'id' => $jsonRequest['id'] ?? null,
                                        'error' => [
                                            'code' => -32603,
                                            'message' => $e->getMessage(),
                                        ],
                                    ];
                                }
                                $responseJson = json_encode($response);

                                $httpResponse = "HTTP/1.1 200 OK\r\n";
                                $httpResponse .= "Content-Type: application/json\r\n";
                                $httpResponse .= 'Content-Length: '.strlen($responseJson)."\r\n";
                                $httpResponse .= "\r\n";
                                $httpResponse .= $responseJson;

                                fwrite($conn, $httpResponse);
                            }
                        }
                        break;
                    }
                }

                fclose($conn);
            }

            fclose($socket);

            return self::SUCCESS;
        } catch (RagException $e) {
            $this->error("RAG error: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
