<?php
declare(strict_types=1);

namespace Sugar\Core\Extension;

use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Compiler\CompilerInterface;

/**
 * Context object provided when materializing runtime services.
 *
 * Unlike RegistrationContext, this context only contains dependencies that are
 * created or only available at render time.
 */
final class RuntimeContext
{
    /**
     * @param \Sugar\Core\Compiler\CompilerInterface $compiler Compiler instance
     * @param \Sugar\Core\Cache\DependencyTracker|null $tracker Dependency tracker for current render
     */
    public function __construct(
        private readonly CompilerInterface $compiler,
        private readonly ?DependencyTracker $tracker,
    ) {
    }

    /**
     * Get compiler.
     */
    public function getCompiler(): CompilerInterface
    {
        return $this->compiler;
    }

    /**
     * Get dependency tracker when available.
     */
    public function getTracker(): ?DependencyTracker
    {
        return $this->tracker;
    }
}
