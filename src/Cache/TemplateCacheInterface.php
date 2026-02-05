<?php
declare(strict_types=1);

namespace Sugar\Cache;

/**
 * Template cache interface
 *
 * Provides caching capabilities for compiled templates with
 * dependency tracking and cascade invalidation.
 */
interface TemplateCacheInterface
{
    /**
     * Retrieve cached template
     *
     * @param string $key Cache key (typically template path)
     * @param bool $debug Enable freshness checking (debug mode)
     * @return \Sugar\Cache\CachedTemplate|null Cached template or null if not found/stale
     */
    public function get(string $key, bool $debug = false): ?CachedTemplate;

    /**
     * Store compiled template
     *
     * @param string $key Cache key (typically template path)
     * @param string $compiled Compiled PHP code
     * @param \Sugar\Cache\CacheMetadata $metadata Template metadata
     * @return string Path to cached file
     */
    public function put(string $key, string $compiled, CacheMetadata $metadata): string;

    /**
     * Invalidate template and dependents (cascade)
     *
     * @param string $key Cache key to invalidate
     * @return array<string> List of invalidated keys
     */
    public function invalidate(string $key): array;

    /**
     * Delete specific cache entry
     *
     * @param string $key Cache key to delete
     * @return bool True if deleted, false if not found
     */
    public function delete(string $key): bool;

    /**
     * Clear all cached templates
     */
    public function flush(): void;
}
