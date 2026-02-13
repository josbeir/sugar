<?php
declare(strict_types=1);

namespace Sugar\Engine;

use PhpParser\Error;
use PhpParser\ParserFactory;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Sugar\Cache\FileCache;
use Sugar\Cache\TemplateCacheInterface;
use Sugar\Compiler\Compiler;
use Sugar\Config\SugarConfig;
use Sugar\Engine;
use Sugar\Escape\Escaper;
use Sugar\Exception\Renderer\HtmlTemplateExceptionRenderer;
use Sugar\Exception\Renderer\TemplateExceptionRendererInterface;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Extension\DirectiveRegistryInterface;
use Sugar\Extension\ExtensionInterface;
use Sugar\Extension\FragmentCacheExtension;
use Sugar\Extension\RegistrationContext;
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

    private bool $phpSyntaxValidation = false;

    private ?object $templateContext = null;

    private ?TemplateExceptionRendererInterface $exceptionRenderer = null;

    private ?CacheInterface $fragmentCache = null;

    private ?int $fragmentCacheTtl = null;

    /**
     * @var array<\Sugar\Extension\ExtensionInterface>
     */
    private array $extensions = [];

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
     * Enable or disable optional PHP syntax validation during compilation.
     *
     * Validation only runs when debug mode is enabled and nikic/php-parser is installed.
     * When disabled (or when debug mode is off), compilation skips parser-based validation
     * and relies on runtime PHP errors.
     *
     * @param bool $enabled Syntax validation enabled
     * @return $this
     */
    public function withPhpSyntaxValidation(bool $enabled = true)
    {
        if ($enabled && (!class_exists(ParserFactory::class) || !class_exists(Error::class))) {
            throw new RuntimeException('nikic/php-parser is required to enable PHP syntax validation');
        }

        $this->phpSyntaxValidation = $enabled;

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
     * Set a template exception renderer
     *
     * @param \Sugar\Exception\Renderer\TemplateExceptionRendererInterface $renderer Exception renderer
     * @return $this
     */
    public function withExceptionRenderer(TemplateExceptionRendererInterface $renderer)
    {
        $this->exceptionRenderer = $renderer;

        return $this;
    }

    /**
     * Set a fragment cache store for the built-in s:cache directive.
     *
     * @param \Psr\SimpleCache\CacheInterface $fragmentCache Fragment cache store
     * @param int|null $ttl Default fragment cache TTL in seconds; null delegates to cache backend defaults
     * @return $this
     */
    public function withFragmentCache(CacheInterface $fragmentCache, ?int $ttl = null)
    {
        $this->fragmentCache = $fragmentCache;
        $this->fragmentCacheTtl = $ttl;

        return $this;
    }

    /**
     * Configure the built-in HTML exception renderer using the current template loader.
     *
     * @param bool $includeStyles Include inline CSS output
     * @param bool $wrapDocument Wrap output in a full HTML document
     * @return $this
     * @throws \RuntimeException If template loader was not configured yet
     */
    public function withHtmlExceptionRenderer(bool $includeStyles = true, bool $wrapDocument = false)
    {
        if (!$this->loader instanceof TemplateLoaderInterface) {
            throw new RuntimeException('Template loader is required before configuring HTML exception renderer');
        }

        return $this->withExceptionRenderer(new HtmlTemplateExceptionRenderer(
            loader: $this->loader,
            includeStyles: $includeStyles,
            wrapDocument: $wrapDocument,
        ));
    }

    /**
     * Register an extension
     *
     * Extensions can provide custom directives and compiler passes.
     * Multiple extensions can be registered and are applied in order.
     *
     * @param \Sugar\Extension\ExtensionInterface $extension Extension to register
     * @return $this
     */
    public function withExtension(ExtensionInterface $extension)
    {
        $this->extensions[] = $extension;

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

        $extensions = $this->extensions;

        if ($this->fragmentCache instanceof CacheInterface) {
            $extensions[] = new FragmentCacheExtension(
                fragmentCache: $this->fragmentCache,
                defaultTtl: $this->fragmentCacheTtl,
            );
        }

        // Process extensions: register directives and collect custom passes
        $customPasses = [];
        foreach ($extensions as $extension) {
            $context = new RegistrationContext();
            $extension->register($context);

            foreach ($context->getDirectives() as $name => $compiler) {
                $registry->register($name, $compiler);
            }

            array_push($customPasses, ...$context->getPasses());
        }

        // Create compiler with all dependencies
        $compiler = new Compiler(
            parser: $parser,
            escaper: $escaper,
            registry: $registry,
            templateLoader: $this->loader,
            config: $this->config,
            customPasses: $customPasses,
            phpSyntaxValidationEnabled: $this->phpSyntaxValidation,
        );

        return new Engine(
            compiler: $compiler,
            loader: $this->loader,
            cache: $this->cache,
            debug: $this->debug,
            templateContext: $this->templateContext,
            exceptionRenderer: $this->exceptionRenderer,
            fragmentCache: $this->fragmentCache,
        );
    }
}
