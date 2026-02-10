<?php
declare(strict_types=1);

namespace Sugar;

use Closure;
use Sugar\Cache\CachedTemplate;
use Sugar\Cache\DependencyTracker;
use Sugar\Cache\TemplateCacheInterface;
use Sugar\Compiler\Compiler;
use Sugar\Config\SugarConfig;
use Sugar\Engine\CompiledTemplateResult;
use Sugar\Engine\EngineBuilder;
use Sugar\Loader\TemplateLoaderInterface;
use Sugar\Runtime\ComponentRenderer;
use Sugar\Runtime\RuntimeEnvironment;
use Sugar\Util\ArrayHelper;
use Sugar\Util\Hash;

/**
 * Sugar template engine
 *
 * High-level API for template compilation and rendering with
 * caching, dependency tracking, and debug mode support.
 */
final class Engine implements EngineInterface
{
    /**
     * @param \Sugar\Compiler\Compiler $compiler Template compiler
     * @param \Sugar\Loader\TemplateLoaderInterface $loader Template loader
     * @param \Sugar\Cache\TemplateCacheInterface $cache Template cache
     * @param bool $debug Debug mode (enables freshness checking)
     * @param object|null $templateContext Optional context object to bind to templates (for $this access)
     */
    public function __construct(
        private readonly Compiler $compiler,
        private readonly TemplateLoaderInterface $loader,
        private readonly TemplateCacheInterface $cache,
        private readonly bool $debug = false,
        private readonly ?object $templateContext = null,
    ) {
    }

    /**
     * Create a builder for fluent engine configuration
     *
     * @param \Sugar\Config\SugarConfig $config Sugar configuration
     * @return \Sugar\Engine\EngineBuilder Builder instance
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
        $blocks = ArrayHelper::normalizeStringList($blocks);

        // Get compiled PHP code
        $compiled = $this->getCompiledTemplate($template, $blocks);

        // Execute the compiled template
        return $this->execute($compiled->path, $data, $compiled->tracker);
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
     * @return \Sugar\Engine\CompiledTemplateResult Compiled path and tracker
     */

    /**
     * @param array<string>|null $blocks
     */
    private function getCompiledTemplate(string $template, ?array $blocks): CompiledTemplateResult
    {
        // Use template as cache key
        $cacheKey = $template;
        if ($blocks !== null) {
            $cacheKey .= '::blocks:' . Hash::make((string)json_encode($blocks));
        }

        // Try to get from cache
        $cached = $this->cache->get($cacheKey, $this->debug);
        if ($cached instanceof CachedTemplate) {
            return new CompiledTemplateResult($cached->path, null);
        }

        // Cache miss or stale - compile and cache
        $source = $this->loader->load($template);

        // Create dependency tracker
        $tracker = new DependencyTracker();

        // Compile with dependency tracking
        $compiled = $this->compiler->compile(
            $source,
            $template,
            $this->debug,
            $tracker,
            $blocks,
        );

        // Build metadata from tracker
        $metadata = $tracker->getMetadata(
            $this->loader->resolveToFilePath($template),
            $this->debug,
        );

        // Store in cache
        $cachedPath = $this->cache->put($cacheKey, $compiled, $metadata);

        return new CompiledTemplateResult($cachedPath, $tracker);
    }

    /**
     * Execute compiled template
     *
     * @param string $compiledPath Path to compiled PHP file
     * @param array<string, mixed> $data Template variables
     * @param \Sugar\Cache\DependencyTracker|null $tracker Dependency tracker to reuse during render
     * @return string Rendered output
     */
    private function execute(string $compiledPath, array $data, ?DependencyTracker $tracker): string
    {
        $renderer = new ComponentRenderer(
            compiler: $this->compiler,
            loader: $this->loader,
            cache: $this->cache,
            tracker: $tracker,
            debug: $this->debug,
            templateContext: $this->templateContext,
        );

        RuntimeEnvironment::setRenderer($renderer);

        try {
            // Include the compiled file and execute the closure
            $fn = include $compiledPath;
            if ($fn instanceof Closure) {
                // Bind to template context if provided (enables $this->helper() calls)
                if ($this->templateContext !== null) {
                    $fn = $fn->bindTo($this->templateContext);
                }

                $result = $fn($data);

                if (is_string($result)) {
                    return $result;
                }

                if (is_scalar($result) || (is_object($result) && method_exists($result, '__toString'))) {
                    return (string)$result;
                }

                return '';
            }

            return '';
        } finally {
            RuntimeEnvironment::clearRenderer();
        }
    }
}
