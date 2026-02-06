<?php
declare(strict_types=1);

namespace Sugar;

use RuntimeException;
use Sugar\Cache\FileCache;
use Sugar\Cache\TemplateCacheInterface;
use Sugar\Config\SugarConfig;
use Sugar\Directive\ClassCompiler;
use Sugar\Directive\ContentCompiler;
use Sugar\Directive\EmptyCompiler;
use Sugar\Directive\ForeachCompiler;
use Sugar\Directive\ForelseCompiler;
use Sugar\Directive\IfCompiler;
use Sugar\Directive\IssetCompiler;
use Sugar\Directive\SpreadCompiler;
use Sugar\Directive\SwitchCompiler;
use Sugar\Directive\UnlessCompiler;
use Sugar\Directive\WhileCompiler;
use Sugar\Enum\OutputContext;
use Sugar\Escape\Escaper;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Loader\TemplateLoaderInterface;
use Sugar\Parser\Parser;

/**
 * Builder for Engine configuration
 *
 * Provides fluent API for configuring the template engine.
 */
final class EngineBuilder
{
    private ?TemplateLoaderInterface $loader = null;

    private ?TemplateCacheInterface $cache = null;

    private ?DirectiveRegistry $registry = null;

    private SugarConfig $config;

    private bool $debug = false;

    private ?object $templateContext = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->config = new SugarConfig();
    }

    /**
     * Set template loader
     *
     * @param \Sugar\Loader\TemplateLoaderInterface $loader Template loader
     * @return $this
     */
    public function withTemplateLoader(TemplateLoaderInterface $loader)
    {
        $this->loader = $loader;

        return $this;
    }

    /**
     * Set cache implementation
     *
     * @param \Sugar\Cache\TemplateCacheInterface $cache Cache implementation
     * @return $this
     */
    public function withCache(TemplateCacheInterface $cache)
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Set custom directive registry
     *
     * Use this to provide a completely custom set of directives,
     * or to add custom directives to the default set.
     *
     * @param \Sugar\Extension\DirectiveRegistry $registry Custom directive registry
     * @return $this
     */
    public function withDirectiveRegistry(DirectiveRegistry $registry)
    {
        $this->registry = $registry;

        return $this;
    }

    /**
     * Set configuration
     *
     * @param \Sugar\Config\SugarConfig $config Configuration
     * @return $this
     */
    public function withConfig(SugarConfig $config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Enable debug mode
     *
     * @param bool $debug Debug mode enabled
     * @return $this
     */
    public function withDebug(bool $debug)
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * Set template context object (for $this binding)
     *
     * @param object|null $templateContext Context object to bind to templates
     * @return $this
     */
    public function withTemplateContext(?object $templateContext)
    {
        $this->templateContext = $templateContext;

        return $this;
    }

    /**
     * Build the engine
     *
     * @return \Sugar\Engine Configured engine instance
     * @throws \RuntimeException If required dependencies are missing
     */
    public function build(): Engine
    {
        if (!$this->loader instanceof TemplateLoaderInterface) {
            throw new RuntimeException('Template loader is required');
        }

        // Provide default cache if not set
        if (!$this->cache instanceof TemplateCacheInterface) {
            $this->cache = new FileCache(
                cacheDir: sys_get_temp_dir() . '/sugar_cache_' . md5(__DIR__),
            );
        }

        // Create compiler dependencies
        $parser = new Parser();
        $escaper = new Escaper();

        // Use provided registry or create default with built-in directives
        $registry = $this->registry ?? $this->createDefaultRegistry();

        // Create compiler with all dependencies
        $compiler = new Compiler(
            parser: $parser,
            escaper: $escaper,
            registry: $registry,
            templateLoader: $this->loader,
            config: $this->config,
        );

        return new Engine(
            compiler: $compiler,
            loader: $this->loader,
            cache: $this->cache,
            debug: $this->debug,
            templateContext: $this->templateContext,
        );
    }

    /**
     * Create default directive registry with all built-in directives
     *
     * @return \Sugar\Extension\DirectiveRegistry Registry with built-in directives
     */
    private function createDefaultRegistry(): DirectiveRegistry
    {
        $registry = new DirectiveRegistry();

        // Register built-in directives (lazy instantiation via class names)
        $registry->register('if', IfCompiler::class);
        $registry->register('elseif', IfCompiler::class);
        $registry->register('else', IfCompiler::class);
        $registry->register('unless', UnlessCompiler::class);
        $registry->register('isset', IssetCompiler::class);
        $registry->register('empty', EmptyCompiler::class);
        $registry->register('switch', SwitchCompiler::class);
        $registry->register('case', SwitchCompiler::class);
        $registry->register('default', SwitchCompiler::class);
        $registry->register('foreach', ForeachCompiler::class);
        $registry->register('forelse', ForelseCompiler::class);
        $registry->register('while', WhileCompiler::class);
        $registry->register('class', ClassCompiler::class);
        $registry->register('spread', SpreadCompiler::class);
        $registry->register('text', new ContentCompiler(escape: true));
        $registry->register('html', new ContentCompiler(escape: false, context: OutputContext::RAW));

        return $registry;
    }
}
