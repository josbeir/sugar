<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\TemplateNotFoundException;

/**
 * Loads templates from filesystem.
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
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     * @param array<string>|string $templatePaths Paths to search for templates (searched in order)
     * @param bool $absolutePathsOnly When true, resolve() ignores current template paths
     */
    public function __construct(
        SugarConfig $config = new SugarConfig(),
        string|array $templatePaths = [],
        bool $absolutePathsOnly = false,
    ) {
        parent::__construct($config, $absolutePathsOnly);

        $templatePaths = is_string($templatePaths) ? [$templatePaths] : $templatePaths;

        if ($templatePaths === []) {
            $cwd = getcwd();
            $this->templatePaths = [$cwd !== false ? $cwd : '.'];
        } else {
            $this->templatePaths = $templatePaths;
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
        if ($this->isAbsolutePath($path)) {
            $resolved = realpath($path);
            if ($resolved !== false) {
                return $resolved;
            }

            $suffix = $this->config->fileSuffix;
            if (!str_ends_with($path, $suffix)) {
                $resolved = realpath($path . $suffix);
                if ($resolved !== false) {
                    return $resolved;
                }
            }
        }

        $resolvedPath = $this->resolve($path, $currentTemplate);
        $fullPath = $this->findTemplateFilePath($resolvedPath, $path);

        return realpath($fullPath) ?: $fullPath;
    }

    /**
     * Determine whether a path is absolute (Unix, Windows drive, or UNC).
     */
    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || ($path[1] ?? '') === ':'
            || str_starts_with($path, '\\\\');
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
    public function listTemplatePaths(string $pathPrefix = ''): array
    {
        $normalizedPrefix = $pathPrefix !== '' ? $this->normalizePath($pathPrefix) : '';
        $prefix = $normalizedPrefix !== '' ? rtrim($normalizedPrefix, '/') . '/' : '';
        $paths = [];

        foreach ($this->templatePaths as $basePath) {
            $rootPath = realpath($basePath);
            if ($rootPath === false) {
                continue;
            }

            if (!is_dir($rootPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo) {
                    continue;
                }

                if (!$file->isFile()) {
                    continue;
                }

                $absolutePath = $file->getPathname();
                if (!str_starts_with($absolutePath, $rootPath . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $relativePath = substr($absolutePath, strlen($rootPath) + 1);
                if ($relativePath === '') {
                    continue;
                }

                $logicalPath = str_replace('\\', '/', $relativePath);
                $logicalPath = $this->normalizePath($logicalPath);

                if ($normalizedPrefix === '') {
                    $paths[$logicalPath] = true;
                    continue;
                }

                if ($logicalPath === $normalizedPrefix || str_starts_with($logicalPath, $prefix)) {
                    $paths[$logicalPath] = true;
                }
            }
        }

        $result = array_keys($paths);
        sort($result);

        return $result;
    }
}
