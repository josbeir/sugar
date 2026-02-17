<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

/**
 * Base class for template loaders with shared name/path normalization.
 */
abstract class AbstractTemplateLoader implements TemplateLoaderInterface
{
    /**
     * @var array<string>
     */
    protected array $defaultSuffixes;

    /**
     * @var array<string, \Sugar\Core\Loader\TemplateNamespaceDefinition>
     */
    protected array $namespaces = [];

    /**
     * Constructor.
     *
     * @param bool $absolutePathsOnly When true, resolve() ignores referrer-relative semantics for non-namespaced input
     * @param array<string> $defaultSuffixes Default suffixes for namespaces without explicit suffixes
     */
    public function __construct(
        protected readonly bool $absolutePathsOnly = false,
        array $defaultSuffixes = ['.sugar.php'],
    ) {
        $this->defaultSuffixes = $defaultSuffixes;
        $this->registerNamespace('app', new TemplateNamespaceDefinition([''], $this->defaultSuffixes));
    }

    /**
     * @inheritDoc
     */
    public function registerNamespace(string $namespace, TemplateNamespaceDefinition $definition): void
    {
        $normalized = $this->normalizeNamespace($namespace);
        $suffixes = $definition->suffixes === []
            ? $this->defaultSuffixes
            : $definition->suffixes;

        $this->namespaces[$normalized] = new TemplateNamespaceDefinition($definition->roots, $suffixes);
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $name, string $referrer = ''): string
    {
        [$namespace, $path, $hasNamespace] = $this->splitLogicalName($name);

        if ($hasNamespace) {
            return $this->formatCanonicalName($namespace, $this->normalizePath($path));
        }

        $referrerNamespace = 'app';
        $referrerPath = '';

        if ($referrer !== '') {
            [$referrerNamespace, $referrerPath] = $this->splitReferrer($referrer);
        }

        if ($this->absolutePathsOnly) {
            return $this->formatCanonicalName('app', $this->normalizePath($name));
        }

        if (str_starts_with($name, '/')) {
            return $this->formatCanonicalName($referrerNamespace, $this->normalizePath($name));
        }

        if ($referrerPath !== '') {
            $currentDir = dirname($referrerPath);
            if ($currentDir === '.') {
                $currentDir = '';
            }

            $combined = $currentDir !== '' ? $currentDir . '/' . $name : $name;

            return $this->formatCanonicalName($referrerNamespace, $this->normalizePath($combined));
        }

        return $this->formatCanonicalName('app', $this->normalizePath($name));
    }

    /**
     * Normalize path by resolving . and .. segments.
     */
    protected function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
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

    /**
     * @return array{string, string, bool}
     *
     * Tuple of namespace, path, and namespace explicitness.
     */
    protected function splitLogicalName(string $name): array
    {
        $trimmed = trim($name);
        if (!str_starts_with($trimmed, '@')) {
            return ['app', $trimmed, false];
        }

        $withoutAt = substr($trimmed, 1);
        $separatorPos = strpos($withoutAt, '/');

        if ($separatorPos === false) {
            return [$this->normalizeNamespace($withoutAt), '', true];
        }

        $namespace = substr($withoutAt, 0, $separatorPos);
        $path = substr($withoutAt, $separatorPos + 1);

        return [$this->normalizeNamespace($namespace), $path, true];
    }

    /**
     * @return array{string, string}
     */
    protected function splitReferrer(string $referrer): array
    {
        [$namespace, $path, $hasNamespace] = $this->splitLogicalName($referrer);
        if ($hasNamespace) {
            return [$namespace, $this->normalizePath($path)];
        }

        return ['app', $this->normalizePath($referrer)];
    }

    /**
     * Format a canonical logical template name.
     */
    protected function formatCanonicalName(string $namespace, string $path): string
    {
        $namespace = $this->normalizeNamespace($namespace);
        $normalizedPath = $this->normalizePath($path);

        if ($normalizedPath === '') {
            return '@' . $namespace;
        }

        return '@' . $namespace . '/' . $normalizedPath;
    }

    /**
     * Normalize a namespace token.
     */
    protected function normalizeNamespace(string $namespace): string
    {
        $normalized = trim($namespace);
        $normalized = ltrim($normalized, '@');

        return $normalized === '' ? 'app' : $normalized;
    }

    /**
     * Retrieve a namespace definition, creating an empty-root default when absent.
     */
    protected function getNamespace(string $namespace): TemplateNamespaceDefinition
    {
        $normalized = $this->normalizeNamespace($namespace);

        if (!isset($this->namespaces[$normalized])) {
            $this->namespaces[$normalized] = new TemplateNamespaceDefinition([''], $this->defaultSuffixes);
        }

        return $this->namespaces[$normalized];
    }

    /**
     * Check if a path already ends with one of the given suffixes.
     *
     * @param array<string> $suffixes
     */
    protected function pathHasAnySuffix(string $path, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if ($suffix !== '' && str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
