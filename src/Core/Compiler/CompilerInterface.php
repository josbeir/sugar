<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler;

use Sugar\Core\Cache\DependencyTracker;

/**
 * Template compiler interface
 */
interface CompilerInterface
{
    /**
     * Compile template source to executable PHP code
     *
     * @param string $source Template source code
     * @param string|null $templatePath Template path for inheritance resolution and debug info (default: null)
     * @param bool $debug Enable debug mode with inline source comments (default: false)
     * @param \Sugar\Core\Cache\DependencyTracker|null $tracker Dependency tracker
     * @param array<string>|null $blocks Restrict output to these block names
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $inlinePasses Additional per-compilation passes
     * @return string Compiled PHP code
     */
    public function compile(
        string $source,
        ?string $templatePath = null,
        bool $debug = false,
        ?DependencyTracker $tracker = null,
        ?array $blocks = null,
        array $inlinePasses = [],
    ): string;
}
