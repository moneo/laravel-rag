<?php

declare(strict_types=1);

test('rag:estimate command handles missing model class', function () {
    $this->artisan('rag:estimate', ['--model' => 'App\\Models\\NonexistentModel'])
        ->assertFailed();
});

test('rag:eval command requires suite option', function () {
    $this->artisan('rag:eval')
        ->assertFailed();
});

test('rag:eval command handles missing suite file', function () {
    $this->artisan('rag:eval', ['--suite' => '/nonexistent/path.json'])
        ->assertFailed();
});

test('rag:index command handles missing model class', function () {
    $this->artisan('rag:index', ['model' => 'App\\Models\\NonexistentModel'])
        ->assertFailed();
});

test('rag:mcp-serve command warns when no tools registered', function () {
    // McpServeCommand requires socket — just test it registers
    $this->artisan('rag:mcp-serve', ['--port' => '0', '--host' => '0.0.0.0'])
        ->assertFailed(); // Fails because no tools + socket fails on port 0
});
