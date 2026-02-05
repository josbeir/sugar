<?php
declare(strict_types=1);

namespace Sugar\TemplateInheritance;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Sugar\Ast\Helper\DirectivePrefixHelper;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Exception\TemplateNotFoundException;

/**
 * Loads templates and components from filesystem
 *
 * Handles both regular template loading (s:include, s:extends) and
 * component discovery and loading (s-button, s-alert).
 */
final class FileTemplateLoader implements TemplateLoaderInterface
{
    /**
     * @var array<string, string> Component name → relative path from basePath
     */
    private array $components = [];

    private DirectivePrefixHelper $prefixHelper;

    /**
     * Constructor.
     *
     * @param string $basePath Base path for template files
     * @param string $elementPrefix Prefix for custom elements (e.g., 's-')
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $elementPrefix = 's-',
    ) {
        // Extract directive prefix from element prefix (e.g., 's-' -> 's')
        $directivePrefix = rtrim($this->elementPrefix, '-');
        $this->prefixHelper = new DirectivePrefixHelper($directivePrefix);
    }

    /**
     * @inheritDoc
     */
    public function load(string $path): string
    {
        $resolvedPath = $this->resolve($path);
        $fullPath = $this->basePath . '/' . ltrim($resolvedPath, '/');

        // Try with the path as-is first
        if (!file_exists($fullPath)) {
            // If not found and doesn't end with .sugar.php, try adding the extension
            if (!str_ends_with($fullPath, '.sugar.php')) {
                $fullPathWithExtension = $fullPath . '.sugar.php';
                if (file_exists($fullPathWithExtension)) {
                    $fullPath = $fullPathWithExtension;
                } else {
                    throw new TemplateNotFoundException(
                        sprintf(
                            'Template "%s" not found at path "%s" or "%s"',
                            $path,
                            $fullPath,
                            $fullPathWithExtension,
                        ),
                    );
                }
            } else {
                throw new TemplateNotFoundException(
                    sprintf('Template "%s" not found at path "%s"', $path, $fullPath),
                );
            }
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new TemplateNotFoundException(
                sprintf('Failed to read template "%s" at path "%s"', $path, $fullPath),
            );
        }

        return $content;
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
            if ($part === '' || $part === '.') {
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
     * @param string $path Relative path from basePath (e.g., 'components')
     */
    public function discoverComponents(string $path): void
    {
        $fullPath = $this->basePath . '/' . ltrim($path, '/');

        if (!is_dir($fullPath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $fragmentElement = $this->elementPrefix . 'template';

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
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

            // Store relative path from basePath
            $relativePath = str_replace($this->basePath . '/', '', $file->getPathname());
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
        if ($elementName === $this->elementPrefix . 'template') {
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
