<?php
declare(strict_types=1);

namespace Sugar\Core\Cache;

/**
 * Tracks template dependencies during compilation
 *
 * Collects all template files that affect a compiled template so cache
 * invalidation can be based on a single dependency list.
 */
final class DependencyTracker
{
    /**
     * @var array<string, true> Dependencies (using keys for uniqueness)
     */
    private array $dependencies = [];

    /**
     * Add a template dependency
     *
     * @param string $path Template path that was extended or included
     */
    public function addDependency(string $path): void
    {
        $this->dependencies[$path] = true;
    }

    /**
     * Get metadata for cache storage
     *
     * @param string $sourcePath Source template path
     * @return \Sugar\Core\Cache\CacheMetadata Metadata with dependencies and timestamps
     */
    public function getMetadata(string $sourcePath, bool $debug = false): CacheMetadata
    {
        $sourceTimestamp = 0;
        if (file_exists($sourcePath)) {
            $mtime = filemtime($sourcePath);
            $sourceTimestamp = $mtime !== false ? $mtime : 0;
        }

        return new CacheMetadata(
            dependencies: array_keys($this->dependencies),
            sourcePath: $sourcePath,
            sourceTimestamp: $sourceTimestamp,
            compiledTimestamp: time(),
            debug: $debug,
        );
    }

    /**
     * Reset tracker for new compilation
     */
    public function reset(): void
    {
        $this->dependencies = [];
    }
}
