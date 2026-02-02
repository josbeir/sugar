<?php
declare(strict_types=1);

namespace Sugar\Core;

/**
 * Immutable value object representing a compiled template
 */
final readonly class CompiledTemplate
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $source,
        public string $compiledCode,
        public int $timestamp,
        public array $metadata = [],
    ) {
    }
}
