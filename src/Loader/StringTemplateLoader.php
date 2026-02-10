<?php
declare(strict_types=1);

namespace Sugar\Loader;

use Sugar\Config\SugarConfig;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Exception\TemplateNotFoundException;

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
     * @param \Sugar\Config\SugarConfig $config Sugar configuration
     * @param array<string, string> $templates Templates (path => source)
     * @param array<string, string> $components Components (name => source)
     * @param bool $absolutePathsOnly When true, resolve() ignores current template paths
     */
    public function __construct(
        SugarConfig $config = new SugarConfig(),
        array $templates = [],
        array $components = [],
        bool $absolutePathsOnly = false,
    ) {
        parent::__construct($config, $absolutePathsOnly);
        $this->templates = $templates;
        $this->components = $components;
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
     * Add a component to the loader
     *
     * @param string $name Component name (e.g., 'button', 'card')
     * @param string $source Component source code
     */
    public function addComponent(string $name, string $source): void
    {
        $this->components[$name] = $source;
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
    public function loadComponent(string $name): string
    {
        if (!isset($this->components[$name])) {
            throw new ComponentNotFoundException(
                sprintf('Component "%s" not found', $name),
            );
        }

        return $this->components[$name];
    }

    /**
     * @inheritDoc
     */
    protected function resolveComponentPath(string $name): string
    {
        // For StringTemplateLoader, use a virtual path for inheritance resolution
        return 'components/' . $name . $this->config->fileSuffix;
    }
}
