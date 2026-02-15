<?php
declare(strict_types=1);

namespace Sugar\Core\Extension;

use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\CompilerInterface;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;

/**
 * Context object provided to extensions during registration
 *
 * Collects directives and compiler passes declared by extensions. The engine
 * builder later applies these registrations to the directive registry and
 * compiler pipeline.
 *
 * Example:
 *
 *     $context->directive('custom', 'CustomDirectiveClass');
 *     $context->compilerPass($customPass, 35);
 */
final class RegistrationContext
{
    /**
     * @var array<string, \Sugar\Core\Directive\Interface\DirectiveInterface|class-string<\Sugar\Core\Directive\Interface\DirectiveInterface>>
     */
    private array $directives = [];

    /**
     * @var array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: int}>
     */
    private array $passes = [];

    /**
     * @var array<string, mixed>
     */
    private array $runtimeServices = [];

    /**
     * Constructor.
     *
     * @param \Sugar\Core\Config\SugarConfig|null $config Sugar configuration when available
     * @param \Sugar\Core\Loader\TemplateLoaderInterface|null $templateLoader Template loader when available
     * @param \Sugar\Core\Cache\TemplateCacheInterface|null $templateCache Template cache when available
     * @param object|null $templateContext Optional template context
     * @param bool $debug Debug mode flag
     * @param \Sugar\Core\Compiler\CompilerInterface|null $compiler Compiler for runtime materialization
     * @param \Sugar\Core\Cache\DependencyTracker|null $tracker Dependency tracker for runtime materialization
     * @param \Sugar\Core\Parser\Parser|null $parser Parser when available
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface|null $directiveRegistry Directive registry when available
     */
    public function __construct(
        private readonly ?SugarConfig $config = null,
        private readonly ?TemplateLoaderInterface $templateLoader = null,
        private readonly ?TemplateCacheInterface $templateCache = null,
        private readonly ?object $templateContext = null,
        private readonly bool $debug = false,
        private readonly ?CompilerInterface $compiler = null,
        private readonly ?DependencyTracker $tracker = null,
        private readonly ?Parser $parser = null,
        private readonly ?DirectiveRegistryInterface $directiveRegistry = null,
    ) {
    }

    /**
     * Register a custom directive compiler
     *
     * @param string $name Directive name (e.g., 'custom', 'tooltip')
     * @param \Sugar\Core\Directive\Interface\DirectiveInterface|class-string<\Sugar\Core\Directive\Interface\DirectiveInterface> $compiler Directive compiler instance or class name
     */
    public function directive(string $name, DirectiveInterface|string $compiler): void
    {
        $this->directives[$name] = $compiler;
    }

    /**
     * Register a custom AST compiler pass
     *
     * @param \Sugar\Core\Compiler\Pipeline\AstPassInterface $pass The compiler pass
     * @param int $priority Ordering priority (negative before, positive after)
     */
    public function compilerPass(AstPassInterface $pass, int $priority = 0): void
    {
        $this->passes[] = ['pass' => $pass, 'priority' => $priority];
    }

    /**
     * Register a named runtime service provided by this extension.
     *
     * @param string $id Service identifier
     * @param mixed $service Service value
     */
    public function runtimeService(string $id, mixed $service): void
    {
        $this->runtimeServices[$id] = $service;
    }

    /**
     * Get all registered directives
     *
     * @return array<string, \Sugar\Core\Directive\Interface\DirectiveInterface|class-string<\Sugar\Core\Directive\Interface\DirectiveInterface>>
     */
    public function getDirectives(): array
    {
        return $this->directives;
    }

    /**
     * Get all registered compiler passes
     *
     * @return array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: int}>
     */
    public function getPasses(): array
    {
        return $this->passes;
    }

    /**
     * Get all registered runtime services.
     *
     * @return array<string, mixed>
     */
    public function getRuntimeServices(): array
    {
        return $this->runtimeServices;
    }

    /**
     * Get Sugar configuration when available.
     */
    public function getConfig(): ?SugarConfig
    {
        return $this->config;
    }

    /**
     * Get template loader when available.
     */
    public function getTemplateLoader(): ?TemplateLoaderInterface
    {
        return $this->templateLoader;
    }

    /**
     * Get template cache when available.
     */
    public function getTemplateCache(): ?TemplateCacheInterface
    {
        return $this->templateCache;
    }

    /**
     * Get template context when available.
     */
    public function getTemplateContext(): ?object
    {
        return $this->templateContext;
    }

    /**
     * Check whether debug mode is enabled for this context.
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Get compiler when available.
     */
    public function getCompiler(): ?CompilerInterface
    {
        return $this->compiler;
    }

    /**
     * Get dependency tracker when available.
     */
    public function getTracker(): ?DependencyTracker
    {
        return $this->tracker;
    }

    /**
     * Get parser when available.
     */
    public function getParser(): ?Parser
    {
        return $this->parser;
    }

    /**
     * Get directive registry when available.
     */
    public function getDirectiveRegistry(): ?DirectiveRegistryInterface
    {
        return $this->directiveRegistry;
    }
}
