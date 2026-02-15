<?php
declare(strict_types=1);

namespace Sugar\Core\Engine;

use Sugar\Core\Cache\DependencyTracker;

/**
 * Value object for a compiled template path and optional dependency tracker.
 */
final readonly class CompiledTemplateResult
{
    /**
     * @param string $path Compiled template path
     * @param \Sugar\Core\Cache\DependencyTracker|null $tracker Dependency tracker from compilation
     */
    public function __construct(
        public string $path,
        public ?DependencyTracker $tracker,
    ) {
    }
}
