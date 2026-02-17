<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Loader;

use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Loader\StringTemplateLoader;

/**
 * In-memory component loader powered by the core string template loader.
 */
final class StringLoader implements ComponentLoaderInterface
{
    private readonly StringTemplateLoader $templateLoader;

    private readonly NamespacedComponentLoader $delegate;

    private readonly string $primarySuffix;

    /**
     * @param array<string, string> $components
     * @param array<string> $suffixes
     */
    public function __construct(
        private readonly SugarConfig $config = new SugarConfig(),
        array $components = [],
        private readonly array $suffixes = ['.sugar.php'],
    ) {
        $this->primarySuffix = $this->suffixes[0] ?? '.sugar.php';

        $templates = [];
        foreach ($components as $name => $source) {
            $templates[$this->componentTemplateKey($name)] = $source;
        }

        $this->templateLoader = new StringTemplateLoader(templates: $templates, suffixes: $this->suffixes);

        $this->delegate = NamespacedComponentLoader::forTemplateLoader(
            templateLoader: $this->templateLoader,
            config: $this->config,
            directories: ['components'],
        );
    }

    /**
     * Add or replace a component source.
     */
    public function addComponent(string $name, string $source): void
    {
        $this->templateLoader->addTemplate($this->componentTemplateKey($name), $source);
    }

    /**
     * @inheritDoc
     */
    public function loadComponent(string $name): string
    {
        return $this->delegate->loadComponent($name);
    }

    /**
     * @inheritDoc
     */
    public function getComponentPath(string $name): string
    {
        return $this->delegate->getComponentPath($name);
    }

    /**
     * @inheritDoc
     */
    public function getComponentFilePath(string $name): string
    {
        return $this->delegate->getComponentFilePath($name);
    }

    /**
     * Build the logical template key for a component.
     */
    private function componentTemplateKey(string $name): string
    {
        return 'components/' . $this->config->elementPrefix . $name . $this->primarySuffix;
    }
}
