<?php
declare(strict_types=1);

namespace Sugar;

use Sugar\Cache\DependencyTracker;

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
     * @return string Compiled PHP code
     */
    public function compile(
        string $source,
        ?string $templatePath = null,
        bool $debug = false,
    ): string;

    /**
     * Compile a component template with runtime slots and attributes
     *
     * @param string $componentName Component name
     * @param array<string> $slotNames Slot variable names to mark as raw
     * @param bool $debug Enable debug mode
     * @param \Sugar\Cache\DependencyTracker|null $tracker Dependency tracker
     * @return string Compiled PHP code
     */
    public function compileComponent(
        string $componentName,
        array $slotNames = [],
        bool $debug = false,
        ?DependencyTracker $tracker = null,
    ): string;
}
