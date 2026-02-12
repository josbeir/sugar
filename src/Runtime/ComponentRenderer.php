<?php
declare(strict_types=1);

namespace Sugar\Runtime;

use Closure;
use ParseError;
use Sugar\Cache\CachedTemplate;
use Sugar\Cache\DependencyTracker;
use Sugar\Cache\TemplateCacheInterface;
use Sugar\Compiler\Compiler;
use Sugar\Exception\CompilationException;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Loader\TemplateLoaderInterface;

/**
 * Renders components at runtime for dynamic component calls
 */
final class ComponentRenderer
{
    /**
     * @param \Sugar\Compiler\Compiler $compiler Compiler instance
     * @param \Sugar\Loader\TemplateLoaderInterface $loader Template loader
     * @param \Sugar\Cache\TemplateCacheInterface $cache Template cache
     * @param \Sugar\Cache\DependencyTracker|null $tracker Optional dependency tracker
     * @param bool $debug Debug mode
     * @param object|null $templateContext Optional template context
     */
    public function __construct(
        private readonly Compiler $compiler,
        private readonly TemplateLoaderInterface $loader,
        private readonly TemplateCacheInterface $cache,
        private readonly ?DependencyTracker $tracker = null,
        private readonly bool $debug = false,
        private readonly ?object $templateContext = null,
    ) {
    }

    /**
     * Render a component by name with bindings, slots, and attributes
     *
     * @param string $name Component name
     * @param array<string, mixed> $vars Bound variables (s:bind)
     * @param array<string, mixed> $slots Slot content
     * @param array<string, mixed> $attributes Runtime attributes
     */
    public function renderComponent(
        string $name,
        array $vars = [],
        array $slots = [],
        array $attributes = [],
    ): string {
        $componentName = trim($name);
        if ($componentName === '') {
            throw new ComponentNotFoundException('Component "" not found');
        }

        $slotNames = array_keys($slots);
        if (!in_array('slot', $slotNames, true)) {
            $slotNames[] = 'slot';
        }

        sort($slotNames);

        $compiledPath = $this->getCompiledComponent($componentName, $slotNames);

        $data = $this->normalizeRenderData($vars, $slots, $attributes);

        return $this->execute($compiledPath, $data);
    }

    /**
     * Compile or retrieve a compiled component variant
     *
     * @param array<string> $slotNames
     */
    private function getCompiledComponent(string $name, array $slotNames): string
    {
        $componentPath = $this->loader->getComponentPath($name);
        $componentSourcePath = $this->loader->getComponentFilePath($name);
        $cacheKey = $componentPath . '::slots:' . implode('|', $slotNames);

        $cached = $this->cache->get($cacheKey, $this->debug);
        if ($cached instanceof CachedTemplate) {
            return $cached->path;
        }

        $tracker = new DependencyTracker();
        $compiled = $this->compiler->compileComponent(
            componentName: $name,
            slotNames: $slotNames,
            debug: $this->debug,
            tracker: $tracker,
        );

        $metadata = $tracker->getMetadata($componentSourcePath, $this->debug);

        if ($this->tracker instanceof DependencyTracker) {
            foreach ($metadata->dependencies as $dependency) {
                $this->tracker->addDependency($dependency);
            }

            foreach ($metadata->components as $component) {
                $this->tracker->addComponent($component);
            }
        }

        return $this->cache->put($cacheKey, $compiled, $metadata);
    }

    /**
     * Normalize render data for component execution
     *
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $slots
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function normalizeRenderData(array $vars, array $slots, array $attributes): array
    {
        $normalizedSlots = [];
        foreach ($slots as $name => $value) {
            $normalizedSlots[$name] = $this->normalizeSlotValue($value);
        }

        if (!isset($normalizedSlots['slot'])) {
            $normalizedSlots['slot'] = '';
        }

        $normalizedAttributes = $this->normalizeAttributes($attributes);

        $data = $vars;
        foreach ($normalizedSlots as $name => $value) {
            $data[$name] = $value;
        }

        $data['__sugar_attrs'] = $normalizedAttributes;

        return $data;
    }

    /**
     * Normalize slot value to a string
     */
    private function normalizeSlotValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string)$value;
        }

        return '';
    }

    /**
     * Normalize attribute array values to stringable values
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function normalizeAttributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $name => $value) {
            $key = (string)$name;

            if ($value === null || is_bool($value) || is_scalar($value)) {
                $normalized[$key] = $value;
                continue;
            }

            if (is_object($value) && method_exists($value, '__toString')) {
                $normalized[$key] = (string)$value;
                continue;
            }

            $normalized[$key] = null;
        }

        return $normalized;
    }

    /**
     * Execute compiled template
     *
     * @param array<string, mixed> $data
     */
    private function execute(string $compiledPath, array $data): string
    {
        try {
            $fn = include $compiledPath;
        } catch (ParseError $parseError) {
            throw CompilationException::fromCompiledComponentParseError($compiledPath, $parseError);
        }

        if ($fn instanceof Closure) {
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
    }
}
