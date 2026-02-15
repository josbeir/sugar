<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\ComponentNotFoundException;

/**
 * Base class for template loaders with common functionality
 */
abstract class AbstractTemplateLoader implements TemplateLoaderInterface
{
    protected readonly DirectivePrefixHelper $prefixHelper;

    /**
     * @var array<string, string> Component name → component identifier (path or source)
     */
    protected array $components = [];

    /**
     * Constructor.
     *
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     * @param bool $absolutePathsOnly When true, resolve() ignores current template paths
     */
    public function __construct(
        protected readonly SugarConfig $config = new SugarConfig(),
        protected readonly bool $absolutePathsOnly = false,
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($this->config->directivePrefix);
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $path, string $currentTemplate = ''): string
    {
        // Absolute paths (starting with /)
        if (str_starts_with($path, '/')) {
            return $this->normalizePath($path);
        }

        if ($this->absolutePathsOnly) {
            return $this->normalizePath($path);
        }

        // Relative paths
        if ($currentTemplate !== '') {
            $currentDir = dirname($currentTemplate);
            $combined = $currentDir . '/' . $path;

            return $this->normalizePath($combined);
        }

        return $this->normalizePath($path);
    }

    /**
     * @inheritDoc
     */
    public function resolveToFilePath(string $path, string $currentTemplate = ''): string
    {
        return $this->resolve($path, $currentTemplate);
    }

    /**
     * Check if component exists
     *
     * @param string $name Component name (e.g., "button")
     * @return bool True if component exists
     */
    public function hasComponent(string $name): bool
    {
        return isset($this->components[$name]);
    }

    /**
     * Check if element name is a registered component
     *
     * @param string $elementName Full element name (e.g., "s-button")
     * @return bool True if registered component, false otherwise
     */
    public function isComponent(string $elementName): bool
    {
        // Must start with element prefix
        if (!$this->prefixHelper->hasElementPrefix($elementName)) {
            return false;
        }

        // Fragment element (s-template) is not a component
        if ($elementName === $this->config->getFragmentElement()) {
            return false;
        }

        // Extract component name and check if registered
        $componentName = $this->prefixHelper->stripElementPrefix($elementName);

        return $this->hasComponent($componentName);
    }

    /**
     * Get component name from element name
     *
     * @param string $elementName Full element name (e.g., "s-button")
     * @return string Component name (e.g., "button")
     */
    public function getComponentName(string $elementName): string
    {
        return $this->prefixHelper->stripElementPrefix($elementName);
    }

    /**
     * @inheritDoc
     */
    public function getComponentPath(string $name): string
    {
        if (!$this->hasComponent($name)) {
            throw new ComponentNotFoundException(
                sprintf('Component "%s" not found', $name),
            );
        }

        return $this->resolveComponentPath($name);
    }

    /**
     * @inheritDoc
     */
    public function getComponentFilePath(string $name): string
    {
        $componentPath = $this->getComponentPath($name);

        return $this->resolveToFilePath($componentPath);
    }

    /**
     * Resolve the path for a component
     *
     * @param string $name Component name
     * @return string Component path for inheritance resolution
     */
    abstract protected function resolveComponentPath(string $name): string;

    /**
     * Get all registered components
     *
     * @return array<string, string> Component name → component identifier
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    /**
     * Normalize path by resolving . and .. segments.
     *
     * @param string $path Path to normalize
     * @return string Normalized path
     */
    protected function normalizePath(string $path): string
    {
        // Remove leading slash for consistent processing
        $path = ltrim($path, '/');
        $parts = explode('/', $path);
        $result = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($result !== []) {
                    array_pop($result);
                }

                continue;
            }

            $result[] = $part;
        }

        return implode('/', $result);
    }
}
