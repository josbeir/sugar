<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\TemplateNotFoundException;
use Sugar\Core\Exception\TemplateRuntimeException;

/**
 * Generic locator for extension resource types backed by core template loaders.
 */
final class ResourceLocator implements ResourceLocatorInterface
{
    /**
     * @var array<string, \Sugar\Core\Loader\ResourceTypeDefinition>
     */
    private array $definitions = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $indexes = [];

    private readonly DirectivePrefixHelper $prefixHelper;

    /**
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $templateLoader Core template loader
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(
        private readonly TemplateLoaderInterface $templateLoader,
        private readonly SugarConfig $config,
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($this->config->directivePrefix);
    }

    /**
     * @inheritDoc
     */
    public function registerType(ResourceTypeDefinition $definition): void
    {
        $this->definitions[$definition->name] = $definition;
        unset($this->indexes[$definition->name]);
    }

    /**
     * @inheritDoc
     */
    public function has(string $type, string $name): bool
    {
        $index = $this->getIndex($type);

        return isset($index[$name]);
    }

    /**
     * @inheritDoc
     */
    public function path(string $type, string $name): string
    {
        $index = $this->getIndex($type);

        if (!isset($index[$name])) {
            throw new TemplateNotFoundException(sprintf('%s "%s" not found', ucfirst($type), $name));
        }

        return $index[$name];
    }

    /**
     * @inheritDoc
     */
    public function filePath(string $type, string $name): string
    {
        return $this->templateLoader->resolveToFilePath($this->path($type, $name));
    }

    /**
     * @inheritDoc
     */
    public function load(string $type, string $name): string
    {
        return $this->templateLoader->load($this->path($type, $name));
    }

    /**
     * @return array<string, string>
     */
    private function getIndex(string $type): array
    {
        if (isset($this->indexes[$type])) {
            return $this->indexes[$type];
        }

        $definition = $this->definitions[$type] ?? null;
        if (!$definition instanceof ResourceTypeDefinition) {
            throw new TemplateRuntimeException(sprintf('Resource type "%s" is not registered', $type));
        }

        $index = [];
        $fragmentElement = $this->config->getFragmentElement();
        $suffix = $this->config->fileSuffix;

        foreach ($definition->directories as $directory) {
            $paths = $this->templateLoader->listTemplatePaths($directory);

            foreach ($paths as $path) {
                if (!str_ends_with($path, $suffix)) {
                    continue;
                }

                $basename = basename($path, $suffix);
                if ($definition->ignoreFragmentElement && $basename === $fragmentElement) {
                    continue;
                }

                $name = $basename;
                if ($definition->stripElementPrefix && $this->prefixHelper->hasElementPrefix($basename)) {
                    $name = $this->prefixHelper->stripElementPrefix($basename);
                }

                $index[$name] = $path;
            }
        }

        $this->indexes[$type] = $index;

        return $index;
    }
}
