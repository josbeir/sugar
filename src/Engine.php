<?php
declare(strict_types=1);

namespace Sugar;

use Closure;
use Sugar\Cache\CachedTemplate;
use Sugar\Cache\DependencyTracker;
use Sugar\Cache\TemplateCacheInterface;
use Sugar\Config\SugarConfig;
use Sugar\Loader\TemplateLoaderInterface;

/**
 * Sugar template engine
 *
 * High-level API for template compilation and rendering with
 * caching, dependency tracking, and debug mode support.
 */
final class Engine implements EngineInterface
{
    /**
     * @param \Sugar\Compiler $compiler Template compiler
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
     * @return \Sugar\EngineBuilder Builder instance
     */
    public static function builder(SugarConfig $config = new SugarConfig()): EngineBuilder
    {
        return new EngineBuilder($config);
    }

    /**
     * @inheritDoc
     */
    public function render(string $template, array $data = []): string
    {
        // Get compiled PHP code
        $compiled = $this->getCompiledTemplate($template);

        // Execute the compiled template
        return $this->execute($compiled, $data);
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
     * @return string Path to compiled PHP file
     */
    private function getCompiledTemplate(string $template): string
    {
        // Use template as cache key
        $cacheKey = $template;

        // Try to get from cache
        $cached = $this->cache->get($cacheKey, $this->debug);
        if ($cached instanceof CachedTemplate) {
            return $cached->path;
        }

        // Cache miss or stale - compile and cache
        $source = $this->loader->load($template);

        // Create dependency tracker
        $tracker = new DependencyTracker();

        // Compile with dependency tracking
        $compiled = $this->compiler->compile($source, $template, $this->debug, $tracker);

        // Build metadata from tracker
        $metadata = $tracker->getMetadata($template);

        // Store in cache
        $cachedPath = $this->cache->put($cacheKey, $compiled, $metadata);

        return $cachedPath;
    }

    /**
     * Execute compiled template
     *
     * @param string $compiledPath Path to compiled PHP file
     * @param array<string, mixed> $data Template variables
     * @return string Rendered output
     */
    private function execute(string $compiledPath, array $data): string
    {
        // Include the compiled file and execute the closure
        $fn = include $compiledPath;
        if ($fn instanceof Closure) {
            // Bind to template context if provided (enables $this->helper() calls)
            if ($this->templateContext !== null) {
                $fn = $fn->bindTo($this->templateContext);
            }

            return $fn($data);
        }

        return '';
    }
}
