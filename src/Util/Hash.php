<?php
declare(strict_types=1);

namespace Sugar\Util;

/**
 * Fast hashing utilities using xxh3
 *
 * Provides consistent, fast hashing for variable names, cache keys,
 * and other internal identifiers using the xxh3 algorithm.
 */
final class Hash
{
    /**
     * Generate a short hash for variable names or identifiers
     *
     * Uses xxh3 (64-bit) which is significantly faster than md5
     * and provides better hash distribution.
     *
     * @param string $data Data to hash
     * @param int $length Length of hash to return (default: 8)
     * @return string Short hash string (hex encoded)
     */
    public static function short(string $data, int $length = 8): string
    {
        $hash = hash('xxh3', $data);

        return substr($hash, 0, $length);
    }

    /**
     * Generate a full hash for cache keys or file paths
     *
     * Returns the full xxh3 64-bit hash (16 hex characters).
     *
     * @param string $data Data to hash
     * @return string Full hash string (16 hex characters)
     */
    public static function make(string $data): string
    {
        return hash('xxh3', $data);
    }
}
