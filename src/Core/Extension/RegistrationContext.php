<?php
declare(strict_types=1);

namespace Sugar\Core\Extension;

use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\Enum\PassPriority;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;

/**
 * Context object provided to extensions during registration.
 *
 * Collects directives, compiler passes, and runtime service factories declared
 * by extensions. The engine builder later applies directives/passes and stores
 * runtime services for render-time materialization.
 *
 * Example:
 *
 *     $context->directive('custom', 'CustomDirectiveClass');
 *     $context->compilerPass($customPass, PassPriority::POST_DIRECTIVE_COMPILATION);
 */
final class RegistrationContext
{
    /**
     * @var array<string, \Sugar\Core\Directive\Interface\DirectiveInterface|class-string<\Sugar\Core\Directive\Interface\DirectiveInterface>>
     */
    private array $directives = [];

    /**
     * @var array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}>
     */
    private array $passes = [];

    /**
     * @var array<string, (\Closure(\Sugar\Core\Extension\RuntimeContext):object)|object>
     */
    private array $runtimeServices = [];

    /**
     * Constructor.
     *
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $templateLoader Template loader
     * @param \Sugar\Core\Cache\TemplateCacheInterface $templateCache Template cache
     * @param \Sugar\Core\Parser\Parser $parser Parser
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $directiveRegistry Directive registry
     * @param object|null $templateContext Optional template context
     * @param bool $debug Debug mode flag
     */
    public function __construct(
        private readonly SugarConfig $config,
        private readonly TemplateLoaderInterface $templateLoader,
        private readonly TemplateCacheInterface $templateCache,
        private readonly Parser $parser,
        private readonly DirectiveRegistryInterface $directiveRegistry,
        private readonly ?object $templateContext = null,
        private readonly bool $debug = false,
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
     * @param \Sugar\Core\Compiler\Pipeline\Enum\PassPriority $priority Ordering priority
     */
    public function compilerPass(
        AstPassInterface $pass,
        PassPriority $priority = PassPriority::POST_DIRECTIVE_COMPILATION,
    ): void {
        $this->passes[] = ['pass' => $pass, 'priority' => $priority];
    }

    /**
     * Register a named runtime service provided by this extension.
     *
     * @param string $id Service identifier
     * @param (\Closure(\Sugar\Core\Extension\RuntimeContext):object)|object $service Service instance or factory closure accepting RuntimeContext
     */
    public function runtimeService(string $id, object $service): void
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
     * @return array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}>
     */
    public function getPasses(): array
    {
        return $this->passes;
    }

    /**
     * Get all registered runtime services.
     *
     * @return array<string, (\Closure(\Sugar\Core\Extension\RuntimeContext):object)|object>
     */
    public function getRuntimeServices(): array
    {
        return $this->runtimeServices;
    }

    /**
     * Get Sugar configuration.
     */
    public function getConfig(): SugarConfig
    {
        return $this->config;
    }

    /**
     * Get template loader.
     */
    public function getTemplateLoader(): TemplateLoaderInterface
    {
        return $this->templateLoader;
    }

    /**
     * Get template cache.
     */
    public function getTemplateCache(): TemplateCacheInterface
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
     * Get parser.
     */
    public function getParser(): Parser
    {
        return $this->parser;
    }

    /**
     * Get directive registry.
     */
    public function getDirectiveRegistry(): DirectiveRegistryInterface
    {
        return $this->directiveRegistry;
    }
}
