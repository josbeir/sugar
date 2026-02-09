<?php
declare(strict_types=1);

namespace Sugar\Context;

use Sugar\Cache\DependencyTracker;
use Sugar\Exception\SnippetGenerator;
use Sugar\Exception\SugarException;

/**
 * Compilation context holding template metadata for error reporting
 *
 * Provides template path, source code, and utilities for creating
 * exceptions with automatic snippet generation.
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
     * Create an exception with automatic snippet generation
     *
     * @param class-string<\Sugar\Exception\SugarException> $exceptionClass Exception class to instantiate
     * @param string $message Error message
     * @param int|null $line Line number in template
     * @param int|null $column Column number in template
     * @return \Sugar\Exception\SugarException The created exception with snippet
     */
    public function createException(
        string $exceptionClass,
        string $message,
        ?int $line = null,
        ?int $column = null,
    ): SugarException {
        $snippet = null;
        if ($line !== null && $column !== null) {
            $generated = SnippetGenerator::generate($this->source, $line, $column);
            $snippet = $generated !== '' ? $generated : null;
        }

        return new $exceptionClass(
            message: $message,
            templatePath: $this->templatePath,
            templateLine: $line,
            templateColumn: $column,
            snippet: $snippet,
        );
    }

    /**
     * Generate a code snippet at the given location
     *
     * @param int $line Line number (1-based)
     * @param int $column Column number (1-based)
     * @param int $contextLines Number of lines to show before and after (default 2)
     * @return string Formatted code snippet with line numbers and error pointer
     */
    public function generateSnippet(int $line, int $column, int $contextLines = 2): string
    {
        return SnippetGenerator::generate($this->source, $line, $column, $contextLines);
    }
}
