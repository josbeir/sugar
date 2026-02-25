<?php
declare(strict_types=1);

namespace Sugar\Core\Runtime;

use Closure;
use ParseError;
use Sugar\Core\Cache\CachedTemplate;
use Sugar\Core\Cache\CacheKey;
use Sugar\Core\Cache\CacheMetadata;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\CompilerInterface;
use Sugar\Core\Exception\CompilationException;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Util\ValueNormalizer;

/**
 * Central runtime service for template rendering, inheritance and includes.
 *
 * Compiles templates on-demand, caches them, and executes the resulting closures.
 * Manages the block inheritance chain via BlockManager and propagates dependencies
 * to the parent DependencyTracker for cache invalidation.
 *
 * This service is used by:
 * - Compiled templates for `s:extends` (renderExtends) and `s:include` (renderInclude)
 * - ComponentRenderer for component template rendering (renderTemplate)
 */
final class TemplateRenderer
{
    /**
     * Stack of templates currently being rendered via extends, for cycle detection.
     *
     * @var array<string, true>
     */
    private array $extendsStack = [];

    /**
     * @param \Sugar\Core\Compiler\CompilerInterface $compiler Template compiler
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $loader Template loader
     * @param \Sugar\Core\Cache\TemplateCacheInterface $cache Template cache
     * @param \Sugar\Core\Runtime\BlockManager $blockManager Block manager for inheritance
     * @param \Sugar\Core\Cache\DependencyTracker|null $tracker Dependency tracker for the current render
     * @param bool $debug Debug mode flag
     * @param object|null $templateContext Template context for $this binding
     */
    public function __construct(
        private readonly CompilerInterface $compiler,
        private readonly TemplateLoaderInterface $loader,
        private readonly TemplateCacheInterface $cache,
        private readonly BlockManager $blockManager,
        private readonly ?DependencyTracker $tracker = null,
        private readonly bool $debug = false,
        private readonly ?object $templateContext = null,
    ) {
    }

    /**
     * Get the block manager for block definition and rendering.
     *
     * @return \Sugar\Core\Runtime\BlockManager Block manager instance
     */
    public function getBlockManager(): BlockManager
    {
        return $this->blockManager;
    }

    /**
     * Check whether a child override exists for the given block name.
     *
     * This reflects only runtime block definitions collected through extends.
     * Parent layout default content does not count as a defined child block.
     */
    public function hasDefinedBlock(string $name): bool
    {
        return $this->blockManager->hasDefinedBlock($name);
    }

    /**
     * Render a parent layout template for extends inheritance.
     *
     * Pushes a new block level before rendering the parent so that child
     * block definitions are available during parent layout rendering.
     *
     * @param string $parentTemplate Parent template path
     * @param array<string, mixed> $data Template variables
     * @return string Rendered parent layout with child block overrides
     */
    public function renderExtends(string $parentTemplate, array $data): string
    {
        $resolved = $this->loader->resolve($parentTemplate);

        if (isset($this->extendsStack[$resolved])) {
            $chain = array_keys($this->extendsStack);
            $chain[] = $resolved;

            throw new TemplateRuntimeException(
                sprintf(
                    'Circular template inheritance detected: %s',
                    implode(' -> ', $chain),
                ),
            );
        }

        $this->extendsStack[$resolved] = true;
        $this->trackDependency($parentTemplate);
        $this->blockManager->pushLevel();

        try {
            return $this->compileAndExecute($parentTemplate, $data);
        } finally {
            unset($this->extendsStack[$resolved]);
            $this->blockManager->popLevel();
        }
    }

    /**
     * Render an included template.
     *
     * The included template receives a copy of the current scope variables
     * and renders independently (no block inheritance interaction).
     *
     * @param string $template Template path to include
     * @param array<string, mixed> $data Template variables (typically get_defined_vars())
     * @return string Rendered include content
     */
    public function renderInclude(string $template, array $data): string
    {
        $this->trackDependency($template);

        // Clean up internal variables that leak from get_defined_vars()
        unset($data['__data'], $data['__e'], $data['__tpl']);

        return $this->compileAndExecute($template, $data);
    }

    /**
     * Render a template with optional inline compiler passes.
     *
     * Used by ComponentRenderer for component templates that need the
     * ComponentVariantAdjustmentPass applied during compilation.
     *
     * @param string $template Template path
     * @param array<string, mixed> $data Template variables
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $inlinePasses Additional compiler passes
     * @param array<string>|null $variantKeys Variant keys for cache differentiation (e.g., slot names)
     * @return string Rendered template content
     */
    public function renderTemplate(
        string $template,
        array $data,
        array $inlinePasses = [],
        ?array $variantKeys = null,
    ): string {
        $this->trackDependency($template);

        $compiledPath = $this->getCompiledTemplate($template, $inlinePasses, $variantKeys);

        return $this->execute($compiledPath, $data);
    }

    /**
     * Add a resolved template source path to the dependency tracker.
     *
     * Used by runtime services (for example ComponentRenderer) when the
     * dependency path is already known and should be recorded directly.
     *
     * @param string $sourcePath Filesystem path of the dependent source file
     */
    public function addDependency(string $sourcePath): void
    {
        $this->tracker?->addDependency($sourcePath);
    }

    /**
     * Compile a template and return its compiled code (without caching).
     *
     * Used when a caller needs only the compiled PHP code string.
     *
     * @param string $template Template path
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $inlinePasses Additional compiler passes
     * @return string Compiled PHP code
     */
    public function compileTemplate(
        string $template,
        array $inlinePasses = [],
    ): string {
        $source = $this->loader->load($template);
        $tracker = new DependencyTracker();

        return $this->compiler->compile(
            source: $source,
            templatePath: $template,
            debug: $this->debug,
            tracker: $tracker,
            inlinePasses: $inlinePasses,
        );
    }

    /**
     * Track a template as a dependency of the current render.
     *
     * @param string $template Template path
     */
    private function trackDependency(string $template): void
    {
        if (!$this->tracker instanceof DependencyTracker) {
            return;
        }

        $resolvedPath = $this->loader->resolve($template);
        $dependencyPath = $this->loader->sourcePath($resolvedPath)
            ?? $this->loader->sourceId($resolvedPath);

        $this->tracker->addDependency($dependencyPath);
    }

    /**
     * Compile and execute a template, caching the result.
     *
     * @param string $template Template path
     * @param array<string, mixed> $data Template variables
     * @return string Rendered content
     */
    private function compileAndExecute(string $template, array $data): string
    {
        $compiledPath = $this->getCompiledTemplate($template);

        return $this->execute($compiledPath, $data);
    }

    /**
     * Get the compiled file path for a template, compiling on cache miss.
     *
     * @param string $template Template path
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $inlinePasses Additional compiler passes
     * @param array<string>|null $variantKeys Cache variant keys
     * @return string Path to the compiled PHP file
     */
    private function getCompiledTemplate(
        string $template,
        array $inlinePasses = [],
        ?array $variantKeys = null,
    ): string {
        $resolvedTemplate = $this->loader->resolve($template);
        $cacheKey = CacheKey::fromTemplate($resolvedTemplate, $variantKeys);

        $cached = $this->cache->get($cacheKey, $this->debug);
        if ($cached instanceof CachedTemplate) {
            return $cached->path;
        }

        $source = $this->loader->load($resolvedTemplate);
        $tracker = new DependencyTracker();

        $compiled = $this->compiler->compile(
            source: $source,
            templatePath: $resolvedTemplate,
            debug: $this->debug,
            tracker: $tracker,
            inlinePasses: $inlinePasses,
        );

        $sourcePath = $this->loader->sourcePath($resolvedTemplate) ?? '';
        $metadata = $tracker->getMetadata($sourcePath, $this->debug);

        $this->propagateDependencies($metadata);

        return $this->cache->put($cacheKey, $compiled, $metadata);
    }

    /**
     * Propagate sub-template dependencies to the parent tracker.
     *
     * @param \Sugar\Core\Cache\CacheMetadata $metadata Sub-template metadata
     */
    private function propagateDependencies(CacheMetadata $metadata): void
    {
        if (!$this->tracker instanceof DependencyTracker) {
            return;
        }

        foreach ($metadata->dependencies as $dependency) {
            $this->tracker->addDependency($dependency);
        }
    }

    /**
     * Execute a compiled template file.
     *
     * @param string $compiledPath Path to the compiled PHP file
     * @param array<string, mixed> $data Template variables
     * @return string Rendered output
     * @throws \Sugar\Core\Exception\CompilationException When compiled file has syntax errors
     * @throws \Sugar\Core\Exception\TemplateRuntimeException When compiled file does not return a closure
     */
    private function execute(string $compiledPath, array $data): string
    {
        try {
            $fn = include $compiledPath;
        } catch (ParseError $parseError) {
            throw CompilationException::fromCompiledTemplateParseError($compiledPath, $parseError);
        }

        if ($fn instanceof Closure) {
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
    }
}
