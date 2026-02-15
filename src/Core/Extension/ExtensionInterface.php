<?php
declare(strict_types=1);

namespace Sugar\Core\Extension;

/**
 * Interface for Sugar template engine extensions
 *
 * Extensions bundle custom directives and compiler passes into a single
 * reusable unit. Implement this interface and register directives or
 * AST passes via the provided RegistrationContext.
 *
 * Example:
 *
 *     final class MyExtension implements ExtensionInterface
 *     {
 *         public function register(RegistrationContext $context): void
 *         {
 *             $context->directive('custom', MyDirective::class);
 *             $context->compilerPass(new MyTransformPass(), \Sugar\Core\Enum\PassPriority::POST_DIRECTIVE_COMPILATION);
 *         }
 *     }
 */
interface ExtensionInterface
{
    /**
     * Register directives and compiler passes with the engine
     *
     * @param \Sugar\Core\Extension\RegistrationContext $context Context for registering directives and passes
     */
    public function register(RegistrationContext $context): void;
}
