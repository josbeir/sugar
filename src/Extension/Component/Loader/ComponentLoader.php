<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Loader;

use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\TemplateNotFoundException;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;

/**
 * Component loader backed directly by core template namespaces.
 */
final readonly class ComponentLoader implements ComponentLoaderInterface
{
    /**
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $templateLoader Core template loader
     * @param \Sugar\Core\Config\SugarConfig $config Sugar config used for component naming
     * @param array<string> $templateNamespaces Template namespaces in priority order. When empty, auto-detects from loader
     * @param array<string> $componentDirectories Component subdirectories within namespaces (e.g. ['components', 'shared'])
     */
    public function __construct(
        private TemplateLoaderInterface $templateLoader,
        private SugarConfig $config,
        private array $templateNamespaces = [],
        private array $componentDirectories = ['components'],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function loadComponent(string $name): string
    {
        $templateName = $this->componentTemplateName($name);

        try {
            return $this->templateLoader->load($templateName);
        } catch (TemplateNotFoundException $templateNotFoundException) {
            throw new ComponentNotFoundException(
                sprintf('Component "%s" not found', $name),
                previous: $templateNotFoundException,
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getComponentPath(string $name): string
    {
        return $this->templateLoader->resolve($this->componentTemplateName($name));
    }

    /**
     * @inheritDoc
     */
    public function getComponentFilePath(string $name): string
    {
        $path = $this->getComponentPath($name);

        return $this->templateLoader->sourcePath($path) ?? $this->templateLoader->sourceId($path);
    }

    /**
     * Build the canonical component template name for the configured namespaces.
     */
    private function componentTemplateName(string $name): string
    {
        $trimmed = trim($name);
        $prefix = $this->config->elementPrefix;

        $normalized = str_starts_with($trimmed, $prefix)
            ? $trimmed
            : $prefix . $trimmed;

        // Auto-detect namespaces from loader if not explicitly configured
        $namespaces = $this->templateNamespaces === []
            ? $this->templateLoader->getRegisteredNamespaces()
            : $this->templateNamespaces;

        // Try each namespace in priority order
        foreach ($namespaces as $namespace) {
            // Then try each component directory within that namespace
            foreach ($this->componentDirectories as $directory) {
                $normalizedDirectory = trim($directory, '/');
                $candidate = $normalizedDirectory === ''
                    ? '@' . $namespace . '/' . $normalized
                    : '@' . $namespace . '/' . $normalizedDirectory . '/' . $normalized;

                if ($this->templateLoader->exists($candidate)) {
                    return $candidate;
                }
            }
        }

        // Fallback to first namespace and first directory
        $fallbackNamespace = $namespaces[0] ?? 'app';
        $fallbackDirectory = trim($this->componentDirectories[0] ?? 'components', '/');

        return $fallbackDirectory === ''
            ? '@' . $fallbackNamespace . '/' . $normalized
            : '@' . $fallbackNamespace . '/' . $fallbackDirectory . '/' . $normalized;
    }
}
