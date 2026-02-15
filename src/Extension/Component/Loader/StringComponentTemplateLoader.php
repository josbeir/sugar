<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Loader;

use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Loader\StringTemplateLoader;

/**
 * In-memory component loader powered by the core string template loader.
 */
final class StringComponentTemplateLoader implements ComponentTemplateLoaderInterface
{
    private readonly StringTemplateLoader $templateLoader;

    private readonly ResourceLocatorComponentTemplateLoader $delegate;

    /**
     * @param array<string, string> $components
     */
    public function __construct(
        private readonly SugarConfig $config = new SugarConfig(),
        array $components = [],
    ) {
        $templates = [];
        foreach ($components as $name => $source) {
            $templates['components/s-' . $name . $this->config->fileSuffix] = $source;
        }

        $this->templateLoader = new StringTemplateLoader(config: $this->config, templates: $templates);
        $this->delegate = ResourceLocatorComponentTemplateLoader::forTemplateLoader(
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
        $this->templateLoader->addTemplate('components/s-' . $name . $this->config->fileSuffix, $source);
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
}
