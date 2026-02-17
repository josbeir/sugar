<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

/**
 * Defines how a namespace resolves logical template names.
 */
final readonly class TemplateNamespaceDefinition
{
    /**
     * @param array<string> $roots Backing roots (filesystem directories or logical prefixes)
     * @param array<string> $suffixes Allowed/auto-appended suffixes in priority order
     */
    public function __construct(
        public array $roots,
        public array $suffixes = [],
    ) {
    }
}
