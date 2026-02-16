<?php
declare(strict_types=1);

namespace Sugar\Core\Engine;

use PhpParser\Error;
use PhpParser\ParserFactory;
use RuntimeException;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\Compiler;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Exception\Renderer\HtmlTemplateExceptionRenderer;
use Sugar\Core\Exception\Renderer\TemplateExceptionRendererInterface;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Core\Util\Hash;

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

    /**
     * @var array<\Sugar\Core\Extension\ExtensionInterface>
     */
    private array $extensions = [];

    /**
     * Constructor
     *
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(SugarConfig $config = new SugarConfig())
    {
        $this->config = $config;
    }

    /**
     * Set template loader
     *
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $loader Template loader
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
     * @param \Sugar\Core\Cache\TemplateCacheInterface $cache Cache implementation
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
     * @param \Sugar\Core\Extension\DirectiveRegistry $registry Custom directive registry
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
     * @param \Sugar\Core\Exception\Renderer\TemplateExceptionRendererInterface $renderer Exception renderer
     * @return $this
     */
    public function withExceptionRenderer(TemplateExceptionRendererInterface $renderer)
    {
        $this->exceptionRenderer = $renderer;

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
     * @param \Sugar\Core\Extension\ExtensionInterface $extension Extension to register
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
     * @return \Sugar\Core\Engine Configured engine instance
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

        // Process extensions: register directives and collect custom passes
        $customPasses = [];
        $runtimeServices = [];
        foreach ($extensions as $extension) {
            $context = new RegistrationContext(
                config: $this->config,
                templateLoader: $this->loader,
                templateCache: $this->cache,
                parser: $parser,
                directiveRegistry: $registry,
                templateContext: $this->templateContext,
                debug: $this->debug,
            );
            $extension->register($context);

            foreach ($context->getDirectives() as $name => $compiler) {
                $registry->register($name, $compiler);
            }

            array_push($customPasses, ...$context->getPasses());
            $incomingRuntimeServices = $context->getRuntimeServices();

            if (array_key_exists(RuntimeEnvironment::RENDERER_SERVICE_ID, $runtimeServices)) {
                unset($incomingRuntimeServices[RuntimeEnvironment::RENDERER_SERVICE_ID]);
            }

            $runtimeServices = [...$runtimeServices, ...$incomingRuntimeServices];
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
            runtimeServices: $runtimeServices,
        );
    }
}
