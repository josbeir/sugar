<?php
declare(strict_types=1);

namespace Sugar\Core\Cache;

/**
 * Represents a cached template
 *
 * Contains the path to the compiled PHP file and its metadata.
 */
final readonly class CachedTemplate
{
    /**
     * @param string $path Path to cached PHP file
     * @param \Sugar\Core\Cache\CacheMetadata $metadata Template metadata
     */
    public function __construct(
        public string $path,
        public CacheMetadata $metadata,
    ) {
    }
}
