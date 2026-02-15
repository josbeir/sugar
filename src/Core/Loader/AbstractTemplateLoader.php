<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

use Sugar\Core\Config\SugarConfig;

/**
 * Base class for template loaders with common functionality
 */
abstract class AbstractTemplateLoader implements TemplateLoaderInterface
{
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
