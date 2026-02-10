<?php
declare(strict_types=1);

namespace Sugar\Extension;

use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Directive\Interface\DirectiveInterface;

/**
 * Context object provided to extensions during registration
 *
 * Collects directives and compiler passes declared by extensions. The engine
 * builder later applies these registrations to the directive registry and
 * compiler pipeline.
 *
 * Example:
 *
 *     $context->directive('custom', MyDirective::class);
 *     $context->compilerPass(new MyPass(), 35);
 */
final class RegistrationContext
{
    /**
     * @var array<string, \Sugar\Directive\Interface\DirectiveInterface|class-string<\Sugar\Directive\Interface\DirectiveInterface>>
     */
    private array $directives = [];

    /**
     * @var array<array{pass: \Sugar\Compiler\Pipeline\AstPassInterface, priority: int}>
     */
    private array $passes = [];

    /**
     * Register a custom directive compiler
     *
     * @param string $name Directive name (e.g., 'custom', 'tooltip')
     * @param \Sugar\Directive\Interface\DirectiveInterface|class-string<\Sugar\Directive\Interface\DirectiveInterface> $compiler Directive compiler instance or class name
     */
    public function directive(string $name, DirectiveInterface|string $compiler): void
    {
        $this->directives[$name] = $compiler;
    }

    /**
     * Register a custom AST compiler pass
     *
     * @param \Sugar\Compiler\Pipeline\AstPassInterface $pass The compiler pass
     * @param int $priority Ordering priority (negative before, positive after)
     */
    public function compilerPass(AstPassInterface $pass, int $priority = 0): void
    {
        $this->passes[] = ['pass' => $pass, 'priority' => $priority];
    }

    /**
     * Get all registered directives
     *
     * @return array<string, \Sugar\Directive\Interface\DirectiveInterface|class-string<\Sugar\Directive\Interface\DirectiveInterface>>
     */
    public function getDirectives(): array
    {
        return $this->directives;
    }

    /**
     * Get all registered compiler passes
     *
     * @return array<array{pass: \Sugar\Compiler\Pipeline\AstPassInterface, priority: int}>
     */
    public function getPasses(): array
    {
        return $this->passes;
    }
}
