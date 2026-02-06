<?php
declare(strict_types=1);

namespace Sugar\Extension;

use RuntimeException;
use Sugar\Enum\DirectiveType;
use Sugar\Exception\DidYouMean;
use Sugar\Exception\UnknownDirectiveException;

/**
 * Registry for directive compilers
 *
 * Manages all registered directive compilers (s:if, s:foreach, s:class, etc.).
 * Provides a single source of truth for directive resolution during compilation.
 *
 * Supports lazy instantiation by accepting class names:
 * ```php
 * $registry = new DirectiveRegistry();
 * $registry->register('if', IfCompiler::class);
 * $registry->register('foreach', new ForeachCompiler());
 *
 * // Later, in compilation:
 * $compiler = $registry->get('if');
 * $code = $compiler->compile($directiveNode);
 * ```
 */
final class DirectiveRegistry
{
    /**
     * @var array<string, \Sugar\Extension\DirectiveCompilerInterface|class-string<\Sugar\Extension\DirectiveCompilerInterface>>
     */
    private array $directives = [];

    /**
     * Register a directive compiler
     *
     * Accepts either an instance or a class name for lazy instantiation.
     *
     * @param string $name Directive name (e.g., 'if', 'foreach', 'while')
     * @param \Sugar\Extension\DirectiveCompilerInterface|class-string<\Sugar\Extension\DirectiveCompilerInterface> $compiler The compiler instance or class name
     */
    public function register(string $name, DirectiveCompilerInterface|string $compiler): void
    {
        $this->directives[$name] = $compiler;
    }

    /**
     * Check if a directive is registered
     *
     * @param string $name Directive name
     * @return bool True if registered, false otherwise
     */
    public function has(string $name): bool
    {
        return isset($this->directives[$name]);
    }

    /**
     * Get a registered directive compiler
     *
     * Instantiates the compiler if a class name was registered.
     *
     * @param string $name Directive name
     * @return \Sugar\Extension\DirectiveCompilerInterface The compiler implementation
     * @throws \Sugar\Exception\UnknownDirectiveException If directive is not registered
     */
    public function get(string $name): DirectiveCompilerInterface
    {
        if (!$this->has($name)) {
            $suggestion = DidYouMean::suggest($name, array_keys($this->directives));

            throw new UnknownDirectiveException(
                directiveName: $name,
                suggestion: $suggestion,
            );
        }

        $compiler = $this->directives[$name];

        // Lazy instantiation: if a class name was registered, instantiate it
        if (is_string($compiler)) {
            $compiler = $this->resolveExtension($compiler, DirectiveCompilerInterface::class);
            $this->directives[$name] = $compiler; // Cache the instance
        }

        return $compiler;
    }

    /**
     * Get all registered directives
     *
     * Resolves all lazy-loaded class strings to instances.
     *
     * @return array<string, \Sugar\Extension\DirectiveCompilerInterface>
     */
    public function all(): array
    {
        // Resolve all lazy instances
        foreach (array_keys($this->directives) as $name) {
            $this->get($name); // This will instantiate and cache it
        }

        /** @var array<string, \Sugar\Extension\DirectiveCompilerInterface> $directives */
        $directives = $this->directives;

        return $directives;
    }

    /**
     * Get directives of a specific type
     *
     * @param \Sugar\Enum\DirectiveType $type Directive type to filter by
     * @return array<string, \Sugar\Extension\DirectiveCompilerInterface> Filtered directives
     */
    public function getByType(DirectiveType $type): array
    {
        $filtered = [];

        foreach (array_keys($this->directives) as $name) {
            $compiler = $this->get($name);
            if ($compiler->getType() === $type) {
                $filtered[$name] = $compiler;
            }
        }

        return $filtered;
    }

    /**
     * Resolve an extension to an instance
     *
     * Validates and instantiates a class string if needed.
     *
     * @template T of object
     * @param T|class-string<T> $extension Extension instance or class name
     * @param class-string<T> $interfaceName Required interface
     * @return T Resolved extension instance
     * @throws \RuntimeException If class doesn't exist or doesn't implement interface
     */
    private function resolveExtension(object|string $extension, string $interfaceName): object
    {
        if (is_string($extension)) {
            if (!class_exists($extension)) {
                throw new RuntimeException(sprintf('Extension class "%s" does not exist', $extension));
            }

            if (!is_subclass_of($extension, $interfaceName)) {
                throw new RuntimeException(
                    sprintf(
                        'Extension class "%s" must implement %s',
                        $extension,
                        $interfaceName,
                    ),
                );
            }

            return new $extension();
        }

        return $extension;
    }
}
