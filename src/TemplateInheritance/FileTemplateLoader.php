<?php
declare(strict_types=1);

namespace Sugar\TemplateInheritance;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Sugar\Ast\Helper\DirectivePrefixHelper;
use Sugar\Config\SugarConfig;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Exception\TemplateNotFoundException;

/**
 * Loads templates and components from filesystem
 *
 * Handles both regular template loading (s:include, s:extends) and
 * component discovery and loading (s-button, s-alert).
 */
class FileTemplateLoader implements TemplateLoaderInterface
{
    /**
     * @var array<string, string> Component name → relative path from basePath
     */
    private array $components = [];

    private DirectivePrefixHelper $prefixHelper;

    /**
     * @var array<string> Template paths to search
     */
    private array $templatePaths;

    /**
     * Constructor.
     *
     * @param \Sugar\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(
        private readonly SugarConfig $config = new SugarConfig(),
    ) {
        if ($this->config->templatePaths === []) {
            $cwd = getcwd();
            $this->templatePaths = [$cwd !== false ? $cwd : '.'];
        } else {
            $this->templatePaths = $this->config->templatePaths;
        }

        $this->prefixHelper = new DirectivePrefixHelper($this->config->directivePrefix);

        // Auto-discover components from config paths
        foreach ($this->config->componentPaths as $path) {
            $this->discoverComponents($path);
        }
    }

    /**
     * @inheritDoc
     */
    public function load(string $path): string
    {
        $resolvedPath = $this->resolve($path);

        // Search all template paths in order
        foreach ($this->templatePaths as $basePath) {
            $fullPath = $basePath . '/' . ltrim($resolvedPath, '/');

            // Try with the path as-is first
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                if ($content === false) {
                    throw new TemplateNotFoundException(
                        sprintf('Failed to read template "%s" at path "%s"', $path, $fullPath),
                    );
                }

                return $content;
            }

            // If not found and doesn't end with .sugar.php, try adding the extension
            if (!str_ends_with($fullPath, '.sugar.php')) {
                $fullPathWithExtension = $fullPath . '.sugar.php';
                if (file_exists($fullPathWithExtension)) {
                    $content = file_get_contents($fullPathWithExtension);
                    if ($content === false) {
                        throw new TemplateNotFoundException(
                            sprintf('Failed to read template "%s" at path "%s"', $path, $fullPathWithExtension),
                        );
                    }

                    return $content;
                }
            }
        }

        // Not found in any path
        throw new TemplateNotFoundException(
            sprintf(
                'Template "%s" not found in paths: %s',
                $path,
                implode(', ', $this->templatePaths),
            ),
        );
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

        // Relative paths
        if ($currentTemplate !== '') {
            $currentDir = dirname($currentTemplate);
            $combined = $currentDir . '/' . $path;

            return $this->normalizePath($combined);
        }

        return $this->normalizePath($path);
    }

    /**
     * Normalize path by resolving . and .. segments.
     *
     * @param string $path Path to normalize
     * @return string Normalized path
     */
    private function normalizePath(string $path): string
    {
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

    /**
     * Discover components in directory
     *
     * Scans recursively for {elementPrefix}*.sugar.php files (e.g., s-button.sugar.php)
     * Excludes {elementPrefix}template.sugar.php (that's the fragment element)
     *
     * @param string $path Relative path from template paths (e.g., 'components')
     */
    public function discoverComponents(string $path): void
    {
        // Scan each template path for components
        foreach ($this->templatePaths as $basePath) {
            $fullPath = $basePath . '/' . ltrim($path, '/');

            if (!is_dir($fullPath)) {
                continue;
            }

            $this->scanComponentDirectory($fullPath);
        }
    }

    /**
     * Scan a directory for component files
     *
     * @param string $fullPath Full path to directory to scan
     */
    private function scanComponentDirectory(string $fullPath): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $fragmentElement = $this->config->getFragmentElement();

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();

            // Must end with .sugar.php
            if (!str_ends_with($filename, '.sugar.php')) {
                continue;
            }

            $basename = $file->getBasename('.sugar.php');

            // Must start with element prefix
            if (!$this->prefixHelper->hasElementPrefix($basename)) {
                continue;
            }

            // Skip fragment element (s-template)
            if ($basename === $fragmentElement) {
                continue;
            }

            // Extract component name (e.g., "button" from "s-button")
            $componentName = $this->prefixHelper->stripElementPrefix($basename);

            // Find relative path from first template path
            $basePath = $this->templatePaths[0];
            $relativePath = str_replace($basePath . '/', '', $file->getPathname());
            $this->components[$componentName] = $relativePath;
        }
    }

    /**
     * Check if component exists
     *
     * @param string $name Component name (e.g., "button", "alert")
     * @return bool True if component is registered
     */
    public function hasComponent(string $name): bool
    {
        return isset($this->components[$name]);
    }

    /**
     * Load component template by name
     *
     * @param string $name Component name (e.g., "button", "alert")
     * @return string Component template content
     * @throws \Sugar\Exception\ComponentNotFoundException
     */
    public function loadComponent(string $name): string
    {
        if (!$this->hasComponent($name)) {
            throw new ComponentNotFoundException(
                sprintf('Component "%s" not found', $name),
            );
        }

        // Reuse existing load() method with relative path
        return $this->load($this->components[$name]);
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
     * Get all registered components
     *
     * @return array<string, string> Component name → relative path
     */
    public function getComponents(): array
    {
        return $this->components;
    }
}
