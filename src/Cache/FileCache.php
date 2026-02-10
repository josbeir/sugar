<?php
declare(strict_types=1);

namespace Sugar\Cache;

/**
 * File-based template cache implementation
 *
 * Stores compiled templates as PHP files with metadata for
 * dependency tracking and freshness validation.
 */
final class FileCache implements TemplateCacheInterface
{
    /**
     * @var array<string, int|null> Request-level stat cache
     */
    private array $mtimeCache = [];

    /**
     * @var array<string, array<string>>|null In-memory dependency map cache (null = not loaded yet)
     */
    private ?array $dependencyMapCache = null;

    /**
     * @var bool Whether dependency map has been modified and needs saving
     */
    private bool $dependencyMapDirty = false;

    /**
     * @var bool Whether stat cache has been cleared for this instance (request)
     */
    private bool $statCacheCleared = false;

    /**
     * @param string $cacheDir Cache directory path
     */
    public function __construct(
        private readonly string $cacheDir,
    ) {
        $this->ensureCacheDirectoryExists();
    }

    /**
     * Destructor ensures dependency map is flushed to disk
     */
    public function __destruct()
    {
        $this->flushDependencyMap();
    }

    /**
     * Flush pending dependency map changes to disk
     *
     * This is called automatically by the destructor but can be
     * called explicitly for long-running processes.
     */
    private function flushDependencyMap(): void
    {
        if ($this->dependencyMapDirty && $this->dependencyMapCache !== null) {
            // Ensure cache directory exists
            $this->ensureCacheDirectoryExists();

            $path = $this->getDependencyMapPath();
            $json = json_encode($this->dependencyMapCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($path, $json);
            $this->dependencyMapDirty = false;
        }
    }

    /**
     * @inheritDoc
     */
    public function get(string $key, bool $debug = false): ?CachedTemplate
    {
        $cachePath = $this->getCachePath($key);

        if (!file_exists($cachePath)) {
            return null;
        }

        $metadataPath = $this->getMetadataPath($cachePath);
        $metadata = $this->loadMetadata($metadataPath);

        if ($metadata->debug !== $debug) {
            return null;
        }

        // Debug mode: check freshness
        if ($debug && !$this->isFresh($metadata)) {
            return null;
        }

        return new CachedTemplate($cachePath, $metadata);
    }

    /**
     * @inheritDoc
     */
    public function put(string $key, string $compiled, CacheMetadata $metadata): string
    {
        $cachePath = $this->getCachePath($key);
        $cacheDir = dirname($cachePath);

        // Create subdirectory if needed
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // Write cache file atomically (temp file + rename)
        $tempPath = $cachePath . '.' . uniqid() . '.tmp';
        file_put_contents($tempPath, $compiled);
        rename($tempPath, $cachePath);

        // Update compiled timestamp to current time
        $metadata = new CacheMetadata(
            dependencies: $metadata->dependencies,
            components: $metadata->components,
            sourcePath: $metadata->sourcePath,
            sourceTimestamp: $metadata->sourceTimestamp,
            compiledTimestamp: time(),
            debug: $metadata->debug,
        );

        // Write metadata
        $metadataPath = $this->getMetadataPath($cachePath);
        $this->saveMetadata($metadataPath, $metadata);

        // Update reverse dependency map
        $this->updateDependencyMap($key, $metadata);

        return $cachePath;
    }

    /**
     * @inheritDoc
     */
    public function invalidate(string $key): array
    {
        $invalidated = [];
        $toInvalidate = [$key];
        $processed = [];

        // Process cascade invalidation recursively
        while ($toInvalidate !== []) {
            $current = array_shift($toInvalidate);

            // Skip if already processed
            if (isset($processed[$current])) {
                continue;
            }

            $processed[$current] = true;

            // Delete the cache
            if ($this->delete($current)) {
                $invalidated[] = $current;
            }

            // Find dependents and add to queue
            $dependents = $this->findDependents($current);
            foreach ($dependents as $dependent) {
                if (!isset($processed[$dependent])) {
                    $toInvalidate[] = $dependent;
                }
            }

            // Clean up from dependency map
            $this->removeFromDependencyMap($current);
        }

        return $invalidated;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): bool
    {
        $cachePath = $this->getCachePath($key);

        if (!file_exists($cachePath)) {
            return false;
        }

        $metadataPath = $this->getMetadataPath($cachePath);

        unlink($cachePath);
        if (file_exists($metadataPath)) {
            unlink($metadataPath);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function flush(): void
    {
        // Clear cache directory
        $this->removeDirectory($this->cacheDir);
        $this->ensureCacheDirectoryExists();

        // Reset in-memory caches
        $this->dependencyMapCache = [];
        $this->dependencyMapDirty = false;
        $this->mtimeCache = [];

        // Write empty dependency map
        $path = $this->getDependencyMapPath();
        file_put_contents($path, '[]');
    }

    /**
     * Generate cache file path with readable name and hash
     *
     * @param string $key Cache key (template path)
     * @return string Full path to cache file
     */
    private function getCachePath(string $key): string
    {
        // Extract template name from key
        $templateName = basename($key, '.sugar.php');
        $templateName = basename($templateName, '.php');

        // Sanitize for filesystem
        $safeName = preg_replace('/[^a-z0-9_-]/i', '-', $templateName);

        // Generate hash for uniqueness
        $hash = hash('xxh3', $key);

        // Subdirectory from first 2 chars
        $subdir = substr($hash, 0, 2);

        // Format: home-abc123def456.php
        $filename = sprintf('%s-%s.php', $safeName, $hash);

        return $this->cacheDir . '/' . $subdir . '/' . $filename;
    }

    /**
     * Get metadata file path for cache file
     *
     * @param string $cachePath Cache file path
     * @return string Metadata file path
     */
    private function getMetadataPath(string $cachePath): string
    {
        return $cachePath . '.meta';
    }

    /**
     * Load metadata from file
     *
     * @param string $path Metadata file path
     * @return \Sugar\Cache\CacheMetadata Metadata object
     */
    private function loadMetadata(string $path): CacheMetadata
    {
        if (!file_exists($path)) {
            return new CacheMetadata();
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return new CacheMetadata();
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new CacheMetadata();
        }

        $dependencies = $data['dependencies'] ?? [];
        if (!is_array($dependencies)) {
            $dependencies = [];
        }

        $dependencies = array_values(array_filter($dependencies, static fn($value): bool => is_string($value)));

        $components = $data['components'] ?? [];
        if (!is_array($components)) {
            $components = [];
        }

        $components = array_values(array_filter($components, static fn($value): bool => is_string($value)));

        $sourcePath = $data['sourcePath'] ?? '';
        if (!is_string($sourcePath)) {
            $sourcePath = '';
        }

        $sourceTimestamp = $data['sourceTimestamp'] ?? 0;
        if (!is_int($sourceTimestamp)) {
            $sourceTimestamp = 0;
        }

        $compiledTimestamp = $data['compiledTimestamp'] ?? 0;
        if (!is_int($compiledTimestamp)) {
            $compiledTimestamp = 0;
        }

        $debug = $data['debug'] ?? false;
        if (!is_bool($debug)) {
            $debug = false;
        }

        return new CacheMetadata(
            dependencies: $dependencies,
            components: $components,
            sourcePath: $sourcePath,
            sourceTimestamp: $sourceTimestamp,
            compiledTimestamp: $compiledTimestamp,
            debug: $debug,
        );
    }

    /**
     * Save metadata to file
     *
     * @param string $path Metadata file path
     * @param \Sugar\Cache\CacheMetadata $metadata Metadata to save
     */
    private function saveMetadata(string $path, CacheMetadata $metadata): void
    {
        $data = [
            'dependencies' => $metadata->dependencies,
            'components' => $metadata->components,
            'sourcePath' => $metadata->sourcePath,
            'sourceTimestamp' => $metadata->sourceTimestamp,
            'compiledTimestamp' => $metadata->compiledTimestamp,
            'debug' => $metadata->debug,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json);
    }

    /**
     * Recursively remove directory and contents
     *
     * @param string $dir Directory path
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * Get path to dependencies map file
     *
     * @return string Path to dependencies.json
     */
    private function getDependencyMapPath(): string
    {
        return $this->cacheDir . '/dependencies.json';
    }

    /**
     * Load reverse dependency map
     *
     * Uses in-memory cache to avoid repeated disk I/O within the same request.
     *
     * @return array<string, array<string>> Map of dependency -> [dependents]
     */
    private function loadDependencyMap(): array
    {
        // Return cached version if already loaded
        if ($this->dependencyMapCache !== null) {
            return $this->dependencyMapCache;
        }

        // Load from disk on first access
        $path = $this->getDependencyMapPath();

        if (!file_exists($path)) {
            $this->dependencyMapCache = [];

            return [];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            $this->dependencyMapCache = [];

            return [];
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            $this->dependencyMapCache = [];

            return [];
        }

        // Cache in memory for subsequent accesses in this request
        /** @var array<string, array<string>> $data */
        $this->dependencyMapCache = $data;

        return $data;
    }

    /**
     * Save reverse dependency map
     *
     * Updates in-memory cache and marks for lazy persistence.
     * Actual disk write happens in flush() or __destruct().
     *
     * @param array<string, array<string>> $map Map to save
     */
    private function saveDependencyMap(array $map): void
    {
        // Update in-memory cache and mark as dirty
        $this->dependencyMapCache = $map;
        $this->dependencyMapDirty = true;
    }

    /**
     * Update reverse dependency map for a template
     *
     * @param string $key Template key
     * @param \Sugar\Cache\CacheMetadata $metadata Template metadata
     */
    private function updateDependencyMap(string $key, CacheMetadata $metadata): void
    {
        $map = $this->loadDependencyMap();

        // Add this template as dependent of all its dependencies
        $allDependencies = array_merge($metadata->dependencies, $metadata->components);
        foreach ($allDependencies as $dependency) {
            if (!isset($map[$dependency])) {
                $map[$dependency] = [];
            }

            if (!in_array($key, $map[$dependency], true)) {
                $map[$dependency][] = $key;
            }
        }

        $this->saveDependencyMap($map);
    }

    /**
     * Find templates that depend on a given key
     *
     * @param string $key Template key
     * @return array<string> List of dependent template keys
     */
    private function findDependents(string $key): array
    {
        $map = $this->loadDependencyMap();

        return $map[$key] ?? [];
    }

    /**
     * Remove template from dependency map
     *
     * @param string $key Template key to remove
     */
    private function removeFromDependencyMap(string $key): void
    {
        $map = $this->loadDependencyMap();

        // Remove as a dependency
        unset($map[$key]);

        // Remove from all dependent lists
        foreach ($map as $dependency => $dependents) {
            $map[$dependency] = array_values(array_filter(
                $dependents,
                fn(string $dependent): bool => $dependent !== $key,
            ));
            // Clean up empty arrays
            if ($map[$dependency] === []) {
                unset($map[$dependency]);
            }
        }

        $this->saveDependencyMap($map);
    }

    /**
     * Check if cached template is fresh
     *
     * @param \Sugar\Cache\CacheMetadata $metadata Cache metadata
     * @return bool True if fresh, false if stale
     */
    private function isFresh(CacheMetadata $metadata): bool
    {
        // Clear stat cache only once per instance (request) to reduce filesystem overhead
        if (!$this->statCacheCleared) {
            clearstatcache();
            $this->statCacheCleared = true;
        }

        // Check source timestamp
        if ($metadata->sourcePath === '') {
            return false;
        }

        $sourceTime = $this->getModTime($metadata->sourcePath);
        if ($sourceTime === null) {
            return false; // Source removed
        }

        if ($sourceTime > $metadata->sourceTimestamp) {
            return false; // Source changed
        }

        if (!$this->areFilesFresh($metadata->dependencies, $metadata->compiledTimestamp)) {
            return false;
        }

        return $this->areFilesFresh($metadata->components, $metadata->compiledTimestamp); // All fresh
    }

    /**
     * Get file modification time with request-level caching
     *
     * @param string $path File path
     * @return int Modification timestamp
     */
    private function getModTime(string $path): ?int
    {
        if (!isset($this->mtimeCache[$path])) {
            if (!is_file($path)) {
                $this->mtimeCache[$path] = null;
            } else {
                $mtime = filemtime($path);
                $this->mtimeCache[$path] = $mtime === false ? null : $mtime;
            }
        }

        return $this->mtimeCache[$path];
    }

    /**
     * @param array<string> $paths
     */
    private function areFilesFresh(array $paths, int $compiledTimestamp): bool
    {
        foreach ($paths as $path) {
            $modTime = $this->getModTime($path);
            if ($modTime === null) {
                return false;
            }

            if ($modTime > $compiledTimestamp) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ensure cache directory exists
     */
    private function ensureCacheDirectoryExists(): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
}
