<?php
declare(strict_types=1);

namespace Sugar\Context;

use Sugar\Cache\DependencyTracker;
use Sugar\Exception\SugarException;

/**
 * Compilation context holding template metadata for error reporting
 *
 * Provides template path, source code, and utilities for creating
 * exceptions with location information.
 */
final class CompilationContext
{
    /**
     * Constructor
     *
     * @param string $templatePath Path to the template file
     * @param string $source Template source code
     * @param bool $debug Enable debug mode
     * @param \Sugar\Cache\DependencyTracker|null $tracker Dependency tracker for cache invalidation
     * @param array<string>|null $blocks Restrict output to these block names
     */
    public function __construct(
        public readonly string $templatePath,
        public readonly string $source,
        public readonly bool $debug = false,
        public readonly ?DependencyTracker $tracker = null,
        public readonly ?array $blocks = null,
    ) {
    }

    /**
     * Create an exception with template location metadata
     *
     * @param class-string<\Sugar\Exception\SugarException> $exceptionClass Exception class to instantiate
     * @param string $message Error message
     * @param int|null $line Line number in template
     * @param int|null $column Column number in template
     * @return \Sugar\Exception\SugarException The created exception
     */
    public function createException(
        string $exceptionClass,
        string $message,
        ?int $line = null,
        ?int $column = null,
    ): SugarException {
        return new $exceptionClass(
            message: $message,
            templatePath: $this->templatePath,
            templateLine: $line,
            templateColumn: $column,
        );
    }
}
