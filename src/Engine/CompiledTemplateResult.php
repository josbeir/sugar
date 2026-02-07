<?php
declare(strict_types=1);

namespace Sugar\Engine;

use Sugar\Cache\DependencyTracker;

/**
 * Value object for a compiled template path and optional dependency tracker.
 */
final readonly class CompiledTemplateResult
{
    /**
     * @param string $path Compiled template path
     * @param \Sugar\Cache\DependencyTracker|null $tracker Dependency tracker from compilation
     */
    public function __construct(
        public string $path,
        public ?DependencyTracker $tracker,
    ) {
    }
}
