<?php
declare(strict_types=1);

namespace Sugar\Cache;

/**
 * Metadata for cached templates
 *
 * Stores information about template dependencies, components,
 * and timestamps for cache freshness validation.
 */
final readonly class CacheMetadata
{
    /**
     * @param array<string> $dependencies Template paths extended/included
     * @param array<string> $components Component paths used
     * @param int $sourceTimestamp Source template modification time
     * @param int $compiledTimestamp Compiled cache creation time
     * @param bool $debug Debug mode used to compile this template
     */
    public function __construct(
        public array $dependencies = [],
        public array $components = [],
        public int $sourceTimestamp = 0,
        public int $compiledTimestamp = 0,
        public bool $debug = false,
    ) {
    }
}
