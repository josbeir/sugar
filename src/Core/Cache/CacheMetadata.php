<?php
declare(strict_types=1);

namespace Sugar\Core\Cache;

/**
 * Metadata for cached templates
 *
 * Stores information about template dependencies and timestamps for cache
 * freshness validation.
 */
final readonly class CacheMetadata
{
    /**
     * @param array<string> $dependencies Template paths extended/included
     * @param string $sourcePath Source template path used for freshness checks
     * @param int $sourceTimestamp Source template modification time
     * @param int $compiledTimestamp Compiled cache creation time
     * @param bool $debug Debug mode used to compile this template
     */
    public function __construct(
        public array $dependencies = [],
        public string $sourcePath = '',
        public int $sourceTimestamp = 0,
        public int $compiledTimestamp = 0,
        public bool $debug = false,
    ) {
    }
}
