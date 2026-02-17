<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Sugar\Core\Exception\TemplateNotFoundException;

/**
 * Loads templates from filesystem namespaces.
 */
class FileTemplateLoader extends AbstractTemplateLoader
{
    /**
     * @var array<string> App namespace template roots
     */
    private array $appRoots;

    /**
     * Constructor.
     *
     * @param array<string>|string $templatePaths App namespace filesystem roots (searched in order)
     * @param bool $absolutePathsOnly When true, resolve() ignores referrer-relative semantics
     * @param array<string> $suffixes Default suffixes for namespace lookups
     */
    public function __construct(
        string|array $templatePaths = [],
        bool $absolutePathsOnly = false,
        array $suffixes = ['.sugar.php'],
    ) {
        parent::__construct($absolutePathsOnly, $suffixes);

        $templatePaths = is_string($templatePaths) ? [$templatePaths] : $templatePaths;

        if ($templatePaths === []) {
            $cwd = getcwd();
            $this->appRoots = [$cwd !== false ? $cwd : '.'];
        } else {
            $this->appRoots = $templatePaths;
        }

        $this->registerNamespace('app', new TemplateNamespaceDefinition($this->appRoots, $suffixes));
    }

    /**
     * @inheritDoc
     */
    public function load(string $name): string
    {
        $fullPath = $this->findTemplateFilePath($name);

        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new TemplateNotFoundException(
                sprintf('Failed to read template "%s" at path "%s"', $name, $fullPath),
            );
        }

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function exists(string $name): bool
    {
        try {
            $this->findTemplateFilePath($name);

            return true;
        } catch (TemplateNotFoundException) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function sourceId(string $name): string
    {
        $resolved = $this->sourcePath($name);
        if ($resolved !== null) {
            return $resolved;
        }

        return $this->resolve($name);
    }

    /**
     * @inheritDoc
     */
    public function sourcePath(string $name): ?string
    {
        try {
            $path = $this->findTemplateFilePath($name);

            return realpath($path) ?: $path;
        } catch (TemplateNotFoundException) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function discover(string $namespace, string $prefix = ''): array
    {
        $definition = $this->getNamespace($namespace);
        $normalizedPrefix = $this->normalizePath($prefix);
        $prefixPath = $normalizedPrefix !== '' ? rtrim($normalizedPrefix, '/') . '/' : '';
        $result = [];

        foreach ($definition->roots as $root) {
            $rootPath = realpath($root);
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

                $relativePath = $this->normalizePath($relativePath);
                if (
                    $normalizedPrefix !== ''
                    && $relativePath !== $normalizedPrefix
                    && !str_starts_with($relativePath, $prefixPath)
                ) {
                    continue;
                }

                $result[$this->formatCanonicalName($namespace, $relativePath)] = true;
            }
        }

        $result = array_keys($result);
        sort($result);

        return $result;
    }

    /**
     * Determine whether a path is absolute (Unix, Windows drive, or UNC).
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || ($path[1] ?? '') === ':'
            || str_starts_with($path, '\\\\');
    }

    /**
     * Find the full template path in configured namespace roots.
     */
    private function findTemplateFilePath(string $name): string
    {
        $canonical = $this->resolve($name);
        [$namespace, $logicalPath] = $this->splitReferrer($canonical);
        $definition = $this->getNamespace($namespace);

        if ($this->isAbsolutePath($logicalPath) && is_file($logicalPath)) {
            return $logicalPath;
        }

        foreach ($definition->roots as $basePath) {
            $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . ltrim($logicalPath, DIRECTORY_SEPARATOR);

            if (is_file($fullPath)) {
                return $fullPath;
            }

            if ($this->pathHasAnySuffix($logicalPath, $definition->suffixes)) {
                continue;
            }

            foreach ($definition->suffixes as $suffix) {
                $fullPathWithExtension = $fullPath . $suffix;
                if (is_file($fullPathWithExtension)) {
                    return $fullPathWithExtension;
                }
            }
        }

        throw new TemplateNotFoundException(
            sprintf('Template "%s" not found in namespace "%s"', $name, $namespace),
        );
    }
}
