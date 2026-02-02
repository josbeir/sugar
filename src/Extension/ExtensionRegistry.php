<?php
declare(strict_types=1);

namespace Sugar\Extension;

use RuntimeException;

/**
 * Unified registry for all template engine extensions
 *
 * This registry manages directives, components, filters, functions, and other
 * extensibility points. It provides a single source of truth for all custom
 * extensions that can be registered by frameworks or applications.
 *
 * Supports lazy instantiation by accepting class names:
 * ```php
 * $registry = new ExtensionRegistry();
 * $registry->registerDirective('if', IfCompiler::class);
 * $registry->registerDirective('foreach', new ForeachCompiler());
 *
 * // Later, in compilation:
 * $compiler = $registry->getDirective('if');
 * $code = $compiler->compile($directiveNode);
 * ```
 */
final class ExtensionRegistry
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
    public function registerDirective(string $name, DirectiveCompilerInterface|string $compiler): void
    {
        $this->directives[$name] = $compiler;
    }

    /**
     * Check if a directive is registered
     *
     * @param string $name Directive name
     * @return bool True if registered, false otherwise
     */
    public function hasDirective(string $name): bool
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
     * @throws \RuntimeException If directive is not registered
     */
    public function getDirective(string $name): DirectiveCompilerInterface
    {
        if (!$this->hasDirective($name)) {
            throw new RuntimeException(sprintf('Directive "%s" is not registered', $name));
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
     * @return array<string, \Sugar\Extension\DirectiveCompilerInterface>
     */
    public function allDirectives(): array
    {
        return $this->directives;
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
