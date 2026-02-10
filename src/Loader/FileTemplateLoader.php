<?php
declare(strict_types=1);

namespace Sugar\Loader;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Sugar\Config\SugarConfig;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Exception\TemplateNotFoundException;

/**
 * Loads templates and components from filesystem
 *
 * Handles both regular template loading (s:include, s:extends) and
 * component discovery and loading (s-button, s-alert).
 */
class FileTemplateLoader extends AbstractTemplateLoader
{
    /**
     * @var array<string> Template paths to search
     */
    private array $templatePaths;

    /**
     * Constructor.
     *
     * @param \Sugar\Config\SugarConfig $config Sugar configuration
     * @param array<string>|string $templatePaths Paths to search for templates (searched in order)
     * @param array<string>|string $componentPaths Paths to scan for component templates
     * @param bool $absolutePathsOnly When true, resolve() ignores current template paths
     */
    public function __construct(
        SugarConfig $config = new SugarConfig(),
        string|array $templatePaths = [],
        string|array $componentPaths = [],
        bool $absolutePathsOnly = false,
    ) {
        parent::__construct($config, $absolutePathsOnly);

        $templatePaths = is_string($templatePaths) ? [$templatePaths] : $templatePaths;
        $componentPaths = is_string($componentPaths) ? [$componentPaths] : $componentPaths;

        if ($templatePaths === []) {
            $cwd = getcwd();
            $this->templatePaths = [$cwd !== false ? $cwd : '.'];
        } else {
            $this->templatePaths = $templatePaths;
        }

        // Auto-discover components from provided paths
        foreach ($componentPaths as $path) {
            $this->discoverComponents($path);
        }
    }

    /**
     * @inheritDoc
     */
    public function load(string $path): string
    {
        $resolvedPath = $this->resolve($path);
        $fullPath = $this->findTemplateFilePath($resolvedPath, $path);

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
    public function resolveToFilePath(string $path, string $currentTemplate = ''): string
    {
        if (str_starts_with($path, '/')) {
            if (is_file($path)) {
                return realpath($path) ?: $path;
            }

            $suffix = $this->config->fileSuffix;
            if (!str_ends_with($path, $suffix) && is_file($path . $suffix)) {
                return realpath($path . $suffix) ?: $path . $suffix;
            }
        }

        $resolvedPath = $this->resolve($path, $currentTemplate);
        $fullPath = $this->findTemplateFilePath($resolvedPath, $path);

        return realpath($fullPath) ?: $fullPath;
    }

    /**
     * Discover components in directory
     *
     * Scans recursively for {elementPrefix}*{fileSuffix} files (e.g., s-button.sugar.php)
     * Excludes {elementPrefix}template{fileSuffix} (that's the fragment element)
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

            $suffix = $this->config->fileSuffix;
            // Must end with the configured suffix
            if (!str_ends_with($filename, $suffix)) {
                continue;
            }

            $basename = $file->getBasename($suffix);

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
            // Normalize path separators for cross-platform consistency
            $relativePath = str_replace('\\', '/', $relativePath);
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
     * Find the full template path in configured template roots.
     */
    private function findTemplateFilePath(string $resolvedPath, string $originalPath): string
    {
        foreach ($this->templatePaths as $basePath) {
            $fullPath = $basePath . '/' . ltrim($resolvedPath, '/');

            if (is_file($fullPath)) {
                return $fullPath;
            }

            $suffix = $this->config->fileSuffix;
            if (!str_ends_with($fullPath, $suffix)) {
                $fullPathWithExtension = $fullPath . $suffix;
                if (is_file($fullPathWithExtension)) {
                    return $fullPathWithExtension;
                }
            }
        }

        throw new TemplateNotFoundException(
            sprintf(
                'Template "%s" not found in paths: %s',
                $originalPath,
                implode(', ', $this->templatePaths),
            ),
        );
    }

    /**
     * @inheritDoc
     */
    protected function resolveComponentPath(string $name): string
    {
        return $this->components[$name];
    }
}
