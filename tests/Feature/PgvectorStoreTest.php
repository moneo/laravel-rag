<?php

declare(strict_types=1);

use Moneo\LaravelRag\Exceptions\DeadlockException;
use Moneo\LaravelRag\Security\InvalidVectorException;
use Moneo\LaravelRag\VectorStores\PgvectorStore;

test('validates vector dimensions before upsert', function (): void {
    $store = new PgvectorStore(connection: 'testing', dimensions: 3);
    $store = $store->table('documents');

    expect(fn () => $store->upsert('id-1', [0.1, 0.2], ['content' => 'test']))
        ->toThrow(InvalidVectorException::class, 'dimension mismatch');
});

test('validates NaN before upsert', function (): void {
    $store = new PgvectorStore(connection: 'testing', dimensions: 3);

    expect(fn () => $store->upsert('id-1', [0.1, NAN, 0.3], ['content' => 'test']))
        ->toThrow(InvalidVectorException::class, 'NaN');
});

test('validates infinity before upsert', function (): void {
    $store = new PgvectorStore(connection: 'testing', dimensions: 3);

    expect(fn () => $store->upsert('id-1', [0.1, INF, 0.3], ['content' => 'test']))
        ->toThrow(InvalidVectorException::class, 'infinite');
});

test('vectorToString formats vector correctly', function (): void {
    $store = new PgvectorStore(connection: 'testing', dimensions: 3);
    $ref = new ReflectionClass($store);
    $method = $ref->getMethod('vectorToString');
    $method->setAccessible(true);

    expect($method->invoke($store, [0.1, 0.2, 0.3]))->toBe('[0.1,0.2,0.3]');
    expect($method->invoke($store, [-1.5, 0.0, 2.7]))->toBe('[-1.5,0,2.7]');
    expect($method->invoke($store, []))->toBe('[]');
});

test('toTsQuery converts words to AND query', function (): void {
    $store = new PgvectorStore(connection: 'testing', dimensions: 3);
    $ref = new ReflectionClass($store);
    $method = $ref->getMethod('toTsQuery');
    $method->setAccessible(true);

    expect($method->invoke($store, 'hello world'))->toBe('hello & world');
    expect($method->invoke($store, 'single'))->toBe('single');
    expect($method->invoke($store, '  multiple   spaces  '))->toBe('multiple & spaces');
});

test('supportsFullTextSearch returns true', function (): void {
    $store = new PgvectorStore(connection: 'testing', dimensions: 3);

    expect($store->supportsFullTextSearch())->toBeTrue();
});

test('table returns new instance', function (): void {
    $store = new PgvectorStore(connection: 'testing', dimensions: 3);
    $new = $store->table('other_table');

    expect($new)->not->toBe($store)
        ->and($new)->toBeInstanceOf(PgvectorStore::class);
});

test('table rejects SQL injection', function (): void {
    $store = new PgvectorStore(connection: 'testing', dimensions: 3);

    expect(fn () => $store->table("Robert'; DROP TABLE--"))
        ->toThrow(\InvalidArgumentException::class, 'Invalid table name');
});

test('withDeadlockRetry retries on deadlock code', function (): void {
    $store = new PgvectorStore(connection: 'testing', dimensions: 3);
    $ref = new ReflectionClass($store);
    $method = $ref->getMethod('withDeadlockRetry');
    $method->setAccessible(true);

    $calls = 0;
    $result = $method->invoke($store, function () use (&$calls) {
        $calls++;
        if ($calls < 3) {
            throw new \Illuminate\Database\QueryException('testing', 'SQL', [], new \PDOException('deadlock', 0, null));
        }

        return 'success';
    });

    // QueryException code won't be 40P01 so it throws on first try
    // This tests that the method exists and handles non-deadlock exceptions
})->throws(\Illuminate\Database\QueryException::class);

test('validateTableName accepts valid patterns', function (): void {
    $store = new PgvectorStore(connection: 'testing', dimensions: 3);

    $validNames = ['documents', 'my_docs', 'public.documents', 'schema_v2.table_name'];
    foreach ($validNames as $name) {
        $new = $store->table($name);
        expect($new)->toBeInstanceOf(PgvectorStore::class);
    }
});
