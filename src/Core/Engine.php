<?php
declare(strict_types=1);

namespace Sugar\Core;

use Closure;
use ParseError;
use Sugar\Core\Cache\CachedTemplate;
use Sugar\Core\Cache\CacheKey;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\Compiler;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\CompilationException;
use Sugar\Core\Exception\Renderer\TemplateExceptionRendererInterface;
use Sugar\Core\Exception\SugarException;
use Sugar\Core\Extension\RuntimeContext;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Core\Util\ValueNormalizer;

/**
 * Sugar template engine
 *
 * High-level API for template compilation and rendering with
 * caching, dependency tracking, and debug mode support.
 */
final class Engine implements EngineInterface
{
    /**
     * @param \Sugar\Core\Compiler\Compiler $compiler Template compiler
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $loader Template loader
     * @param \Sugar\Core\Cache\TemplateCacheInterface $cache Template cache
     * @param bool $debug Debug mode (enables freshness checking)
     * @param object|null $templateContext Optional context object to bind to templates (for $this access)
     * @param \Sugar\Core\Exception\Renderer\TemplateExceptionRendererInterface|null $exceptionRenderer Exception renderer
     * @param array<string, mixed> $runtimeServices Named runtime services exposed during template execution
     */
    public function __construct(
        private readonly Compiler $compiler,
        private readonly TemplateLoaderInterface $loader,
        private readonly TemplateCacheInterface $cache,
        private readonly bool $debug = false,
        private readonly ?object $templateContext = null,
        private readonly ?TemplateExceptionRendererInterface $exceptionRenderer = null,
        private readonly array $runtimeServices = [],
    ) {
    }

    /**
     * Create a builder for fluent engine configuration
     *
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     * @return \Sugar\Core\EngineBuilder Builder instance
     */
    public static function builder(SugarConfig $config = new SugarConfig()): EngineBuilder
    {
        return new EngineBuilder($config);
    }

    /**
     * @inheritDoc
     */
    public function render(string $template, array $data = [], ?array $blocks = null): string
    {
        $blocks = ValueNormalizer::normalizeStringList($blocks);

        try {
            // Get compiled PHP code
            [$compiledPath, $tracker] = $this->getCompiledTemplate($template, $blocks);

            // Execute the compiled template
            return $this->execute($compiledPath, $data, $tracker);
        } catch (SugarException $sugarException) {
            if ($this->debug && $this->exceptionRenderer instanceof TemplateExceptionRendererInterface) {
                return $this->exceptionRenderer->render($sugarException);
            }

            throw $sugarException;
        }
    }

    /**
     * @inheritDoc
     */
    public function compile(string $template): string
    {
        $source = $this->loader->load($template);

        return $this->compiler->compile($source);
    }

    /**
     * Get compiled template (from cache or compile fresh)
     *
     * @param string $template Template path
     * @param array<string>|null $blocks
     * @return array{0: string, 1: \Sugar\Core\Cache\DependencyTracker|null} Compiled path and tracker
     */
    private function getCompiledTemplate(string $template, ?array $blocks): array
    {
        $canonicalTemplate = $this->loader->resolve($template);

        // Use template as cache key
        $cacheKey = CacheKey::fromTemplate($canonicalTemplate, $blocks);

        // Try to get from cache
        $cached = $this->cache->get($cacheKey, $this->debug);
        if ($cached instanceof CachedTemplate) {
            return [$cached->path, null];
        }

        // Cache miss or stale - compile and cache
        $source = $this->loader->load($canonicalTemplate);

        // Create dependency tracker
        $tracker = new DependencyTracker();

        // Compile with dependency tracking
        $compiled = $this->compiler->compile(
            $source,
            $canonicalTemplate,
            $this->debug,
            $tracker,
            $blocks,
        );

        $sourcePath = $this->loader->sourcePath($canonicalTemplate) ?? '';

        // Build metadata from tracker
        $metadata = $tracker->getMetadata(
            $sourcePath,
            $this->debug,
        );

        // Store in cache
        $cachedPath = $this->cache->put($cacheKey, $compiled, $metadata);

        return [$cachedPath, $tracker];
    }

    /**
     * Execute compiled template
     *
     * @param string $compiledPath Path to compiled PHP file
     * @param array<string, mixed> $data Template variables
     * @param \Sugar\Core\Cache\DependencyTracker|null $tracker Dependency tracker to reuse during render
     * @return string Rendered output
     */
    private function execute(string $compiledPath, array $data, ?DependencyTracker $tracker): string
    {
        $runtimeContext = new RuntimeContext(
            compiler: $this->compiler,
            tracker: $tracker,
        );

        RuntimeEnvironment::set($this->materializeRuntimeServices($runtimeContext));

        try {
            // Include the compiled file and execute the closure
            try {
                $fn = include $compiledPath;
            } catch (ParseError $parseError) {
                throw CompilationException::fromCompiledTemplateParseError($compiledPath, $parseError);
            }

            if ($fn instanceof Closure) {
                // Bind to template context if provided (enables $this->helper() calls)
                if ($this->templateContext !== null) {
                    $fn = $fn->bindTo($this->templateContext);
                }

                $result = $fn($data);

                if (is_string($result)) {
                    return $result;
                }

                return ValueNormalizer::toDisplayString($result);
            }

            return '';
        } finally {
            RuntimeEnvironment::clear();
        }
    }

    /**
     * Materialize runtime services for a single template execution.
     *
     * Services may be either concrete values or closures that accept
     * RuntimeContext and return a concrete runtime service.
     *
     * @param \Sugar\Core\Extension\RuntimeContext $runtimeContext Runtime materialization context
     * @return array<string, mixed>
     */
    private function materializeRuntimeServices(RuntimeContext $runtimeContext): array
    {
        $services = [];

        foreach ($this->runtimeServices as $id => $service) {
            if ($service instanceof Closure) {
                $services[$id] = $service($runtimeContext);
                continue;
            }

            $services[$id] = $service;
        }

        return $services;
    }
}
