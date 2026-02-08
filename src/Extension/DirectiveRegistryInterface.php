<?php
declare(strict_types=1);

namespace Sugar\Extension;

use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Enum\DirectiveType;

/**
 * Interface for directive compiler registries
 *
 * Defines the contract for managing directive compilers (s:if, s:foreach, s:class, etc.).
 * Implementations provide a single source of truth for directive resolution during compilation.
 *
 * This interface enables:
 * - Custom directive registry implementations
 * - Alternative storage backends (database, cache, etc.)
 * - Testing with mock registries
 * - Framework-specific registry adaptations
 */
interface DirectiveRegistryInterface
{
    /**
     * Register a directive compiler
     *
     * Accepts either an instance or a class name for lazy instantiation.
     *
     * @param string $name Directive name (e.g., 'if', 'foreach', 'while')
     * @param \Sugar\Directive\Interface\DirectiveInterface|class-string<\Sugar\Directive\Interface\DirectiveInterface> $compiler The compiler instance or class name
     */
    public function register(string $name, DirectiveInterface|string $compiler): void;

    /**
     * Check if a directive is registered
     *
     * @param string $name Directive name
     * @return bool True if registered, false otherwise
     */
    public function has(string $name): bool;

    /**
     * Get a registered directive compiler
     *
     * Instantiates the compiler if a class name was registered.
     *
     * @param string $name Directive name
     * @return \Sugar\Directive\Interface\DirectiveInterface The compiler implementation
     * @throws \Sugar\Exception\UnknownDirectiveException If directive is not registered
     */
    public function get(string $name): DirectiveInterface;

    /**
     * Get all registered directives
     *
     * Resolves all lazy-loaded class strings to instances.
     *
     * @return array<string, \Sugar\Directive\Interface\DirectiveInterface>
     */
    public function all(): array;

    /**
     * Get directives of a specific type
     *
     * @param \Sugar\Enum\DirectiveType $type Directive type to filter by
     * @return array<string, \Sugar\Directive\Interface\DirectiveInterface> Filtered directives
     */
    public function getByType(DirectiveType $type): array;
}
