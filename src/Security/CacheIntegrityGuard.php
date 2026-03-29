<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Security;

class CacheIntegrityGuard
{
    /**
     * Generate an HMAC-signed cache key from source text.
     *
     * @param  string  $text  The source text to hash
     * @param  string  $appKey  The application key for HMAC signing
     * @return string  The HMAC-signed hash
     */
    public static function signedHash(string $text, string $appKey): string
    {
        return hash_hmac('sha256', $text, $appKey);
    }

    /**
     * Verify that a cache key matches the expected text.
     *
     * @param  string  $hash  The stored cache key
     * @param  string  $text  The source text to verify against
     * @param  string  $appKey  The application key for HMAC signing
     * @return bool  True if the hash is valid
     */
    public static function verify(string $hash, string $text, string $appKey): bool
    {
        $expected = self::signedHash($text, $appKey);

        return hash_equals($expected, $hash);
    }

    /**
     * Validate that a cached embedding is a valid float array.
     *
     * @param  mixed  $decoded  The JSON-decoded cache value
     *
     * @throws CacheIntegrityException
     *
     * @return array<int, float>  The validated float array
     */
    public static function validateCachedVector(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            throw new CacheIntegrityException(
                'Cached embedding is not an array. Possible cache corruption.'
            );
        }

        if ($decoded === []) {
            throw new CacheIntegrityException(
                'Cached embedding is empty. Possible cache corruption.'
            );
        }

        foreach ($decoded as $index => $value) {
            if (! is_float($value) && ! is_int($value)) {
                throw new CacheIntegrityException(
                    "Cached embedding element at index {$index} is not a number. Possible cache corruption."
                );
            }
        }

        return array_map(floatval(...), $decoded);
    }
}
