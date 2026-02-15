<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

/**
 * Defines how a logical resource type maps to templates in the loader backend.
 */
final readonly class ResourceTypeDefinition
{
    /**
     * @param string $name Resource type name (e.g. "component")
     * @param array<string> $directories Logical directories to scan
     * @param bool $stripElementPrefix Strip configured element prefix from names when present
     * @param bool $ignoreFragmentElement Ignore fragment element name when scanning
     */
    public function __construct(
        public string $name,
        public array $directories,
        public bool $stripElementPrefix = true,
        public bool $ignoreFragmentElement = true,
    ) {
    }
}
