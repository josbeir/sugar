<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

use Sugar\Core\Exception\TemplateNotFoundException;

/**
 * Loads templates from in-memory string sources.
 *
 * Useful for testing or when templates are stored in a database/cache
 * instead of the filesystem.
 */
class StringTemplateLoader extends AbstractTemplateLoader
{
    /**
     * @var array<string, string> Template name → source code
     */
    private array $templates = [];

    /**
     * Constructor.
     *
     * @param array<string, string> $templates Templates (logical name => source)
     * @param bool $absolutePathsOnly When true, resolve() ignores referrer-relative semantics
     * @param array<string> $suffixes Default suffixes for namespace lookups
     */
    public function __construct(
        array $templates = [],
        bool $absolutePathsOnly = false,
        array $suffixes = ['.sugar.php'],
    ) {
        parent::__construct($absolutePathsOnly, $suffixes);

        foreach ($templates as $path => $source) {
            $canonical = $this->resolve($path);
            $this->templates[$canonical] = $source;
        }
    }

    /**
     * Add a template to the loader
     *
     * @param string $path Logical template name
     * @param string $source Template source code
     */
    public function addTemplate(string $path, string $source): void
    {
        $this->templates[$this->resolve($path)] = $source;
    }

    /**
     * @inheritDoc
     */
    public function load(string $name): string
    {
        $canonical = $this->resolve($name);

        // Try exact canonical match first
        if (isset($this->templates[$canonical])) {
            return $this->templates[$canonical];
        }

        [$namespace, $logicalPath] = $this->splitReferrer($canonical);
        $definition = $this->getNamespace($namespace);

        $basePaths = [$logicalPath];

        if ($namespace !== 'app') {
            foreach ($definition->roots as $root) {
                $normalizedRoot = $this->normalizePath($root);
                if ($normalizedRoot === '') {
                    $basePaths[] = $logicalPath;
                    continue;
                }

                $basePaths[] = $normalizedRoot . '/' . $logicalPath;
            }
        }

        $basePaths = array_values(array_unique($basePaths));

        foreach ($basePaths as $basePath) {
            $candidate = $this->formatCanonicalName($namespace, $basePath);
            if (isset($this->templates[$candidate])) {
                return $this->templates[$candidate];
            }

            if ($namespace !== 'app') {
                $appCandidate = $this->formatCanonicalName('app', $basePath);
                if (isset($this->templates[$appCandidate])) {
                    return $this->templates[$appCandidate];
                }
            }
        }

        if ($this->pathHasAnySuffix($logicalPath, $definition->suffixes)) {
            throw new TemplateNotFoundException(sprintf('Template "%s" not found', $name));
        }

        foreach ($basePaths as $basePath) {
            foreach ($definition->suffixes as $suffix) {
                $candidate = $this->formatCanonicalName($namespace, $basePath . $suffix);
                if (isset($this->templates[$candidate])) {
                    return $this->templates[$candidate];
                }

                if ($namespace !== 'app') {
                    $appCandidate = $this->formatCanonicalName('app', $basePath . $suffix);
                    if (isset($this->templates[$appCandidate])) {
                        return $this->templates[$appCandidate];
                    }
                }
            }
        }

        throw new TemplateNotFoundException(
            sprintf('Template "%s" not found', $name),
        );
    }

    /**
     * @inheritDoc
     */
    public function exists(string $name): bool
    {
        try {
            $this->load($name);

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
        return $this->resolve($name);
    }

    /**
     * @inheritDoc
     */
    public function sourcePath(string $name): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function discover(string $namespace, string $prefix = ''): array
    {
        $normalizedNamespace = $this->normalizeNamespace($namespace);
        $normalizedPrefix = $this->normalizePath($prefix);
        $prefixPath = $normalizedPrefix !== '' ? rtrim($normalizedPrefix, '/') . '/' : '';
        $result = [];

        foreach (array_keys($this->templates) as $canonical) {
            [$templateNamespace, $templatePath] = $this->splitReferrer($canonical);
            if ($templateNamespace !== $normalizedNamespace) {
                continue;
            }

            if (
                $normalizedPrefix !== ''
                && $templatePath !== $normalizedPrefix
                && !str_starts_with($templatePath, $prefixPath)
            ) {
                continue;
            }

            $result[] = $canonical;
        }

        sort($result);

        return $result;
    }
}
