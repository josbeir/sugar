<?php
declare(strict_types=1);

namespace Sugar\Cache;

/**
 * Tracks template dependencies during compilation
 *
 * Collects information about templates extended/included and
 * components used to enable cache invalidation.
 */
final class DependencyTracker
{
    /**
     * @var array<string, true> Dependencies (using keys for uniqueness)
     */
    private array $dependencies = [];

    /**
     * @var array<string, true> Components (using keys for uniqueness)
     */
    private array $components = [];

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
     * Add a component dependency
     *
     * @param string $path Component path that was used
     */
    public function addComponent(string $path): void
    {
        $this->components[$path] = true;
    }

    /**
     * Get metadata for cache storage
     *
     * @param string $sourcePath Source template path
     * @return \Sugar\Cache\CacheMetadata Metadata with dependencies and timestamps
     */
    public function getMetadata(string $sourcePath): CacheMetadata
    {
        $sourceTimestamp = 0;
        if (file_exists($sourcePath)) {
            $mtime = filemtime($sourcePath);
            $sourceTimestamp = $mtime !== false ? $mtime : 0;
        }

        return new CacheMetadata(
            dependencies: array_keys($this->dependencies),
            components: array_keys($this->components),
            sourceTimestamp: $sourceTimestamp,
            compiledTimestamp: time(),
        );
    }

    /**
     * Reset tracker for new compilation
     */
    public function reset(): void
    {
        $this->dependencies = [];
        $this->components = [];
    }
}
