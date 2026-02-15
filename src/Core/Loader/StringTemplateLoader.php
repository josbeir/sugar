<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\TemplateNotFoundException;

/**
 * Loads templates from in-memory strings
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
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     * @param array<string, string> $templates Templates (path => source)
     * @param bool $absolutePathsOnly When true, resolve() ignores current template paths
     */
    public function __construct(
        SugarConfig $config = new SugarConfig(),
        array $templates = [],
        bool $absolutePathsOnly = false,
    ) {
        parent::__construct($config, $absolutePathsOnly);
        $this->templates = $templates;
    }

    /**
     * Add a template to the loader
     *
     * @param string $path Template path/name
     * @param string $source Template source code
     */
    public function addTemplate(string $path, string $source): void
    {
        $this->templates[$path] = $source;
    }

    /**
     * @inheritDoc
     */
    public function load(string $path): string
    {
        $normalized = $this->normalizePath($path);

        // Try exact match first
        if (isset($this->templates[$normalized])) {
            return $this->templates[$normalized];
        }

        $suffix = $this->config->fileSuffix;

        // Try with configured suffix
        if (isset($this->templates[$normalized . $suffix])) {
            return $this->templates[$normalized . $suffix];
        }

        // Try with .php extension
        if (isset($this->templates[$normalized . '.php'])) {
            return $this->templates[$normalized . '.php'];
        }

        throw new TemplateNotFoundException(
            sprintf('Template "%s" not found', $path),
        );
    }

    /**
     * @inheritDoc
     */
    public function listTemplatePaths(string $pathPrefix = ''): array
    {
        $normalizedPrefix = $pathPrefix !== '' ? $this->normalizePath($pathPrefix) : '';
        $templates = array_keys($this->templates);

        if ($normalizedPrefix === '') {
            sort($templates);

            return $templates;
        }

        $prefix = rtrim($normalizedPrefix, '/') . '/';
        $result = [];

        foreach ($templates as $path) {
            if ($path === $normalizedPrefix || str_starts_with($path, $prefix)) {
                $result[] = $path;
            }
        }

        sort($result);

        return $result;
    }
}
