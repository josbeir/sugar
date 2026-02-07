<?php
declare(strict_types=1);

namespace Sugar;

use RuntimeException;
use Sugar\Cache\FileCache;
use Sugar\Cache\TemplateCacheInterface;
use Sugar\Compiler\Compiler;
use Sugar\Config\SugarConfig;
use Sugar\Escape\Escaper;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Extension\DirectiveRegistryInterface;
use Sugar\Loader\TemplateLoaderInterface;
use Sugar\Parser\Parser;
use Sugar\Util\Hash;

/**
 * Builder for Engine configuration
 *
 * Provides fluent API for configuring the template engine.
 */
final class EngineBuilder
{
    private ?TemplateLoaderInterface $loader = null;

    private ?TemplateCacheInterface $cache = null;

    private ?DirectiveRegistryInterface $registry = null;

    private SugarConfig $config;

    private bool $debug = false;

    private ?object $templateContext = null;

    /**
     * Constructor
     *
     * @param \Sugar\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(SugarConfig $config = new SugarConfig())
    {
        $this->config = $config;
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
                cacheDir: sys_get_temp_dir() . '/sugar_cache_' . Hash::make(__DIR__),
            );
        }

        // Create compiler dependencies
        $parser = new Parser();
        $escaper = new Escaper();

        // Use provided registry or create new one with defaults
        $registry = $this->registry ?? new DirectiveRegistry();

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
}
