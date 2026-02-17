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
final readonly class NamespacedComponentLoader implements ComponentLoaderInterface
{
    /**
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $templateLoader Core template loader
     * @param \Sugar\Core\Config\SugarConfig $config Sugar config used for component naming
     * @param array<string> $directories Logical directories containing component templates
     */
    public function __construct(
        private TemplateLoaderInterface $templateLoader,
        private SugarConfig $config,
        private array $directories = ['components'],
    ) {
    }

    /**
     * Factory helper for component extension wiring.
     *
     * @param array<string> $directories
     */
    public static function forTemplateLoader(
        TemplateLoaderInterface $templateLoader,
        SugarConfig $config,
        array $directories = ['components'],
    ): self {
        return new self($templateLoader, $config, $directories);
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
     * Build the canonical component template name for the configured namespace.
     */
    private function componentTemplateName(string $name): string
    {
        $trimmed = trim($name);
        $prefix = $this->config->elementPrefix;

        $normalized = str_starts_with($trimmed, $prefix)
            ? $trimmed
            : $prefix . $trimmed;

        foreach ($this->directories as $directory) {
            $normalizedDirectory = trim($directory, '/');
            $candidate = $normalizedDirectory === ''
                ? '@app/' . $normalized
                : '@app/' . $normalizedDirectory . '/' . $normalized;

            if ($this->templateLoader->exists($candidate)) {
                return $candidate;
            }
        }

        $fallbackDirectory = trim($this->directories[0] ?? 'components', '/');

        return $fallbackDirectory === ''
            ? '@app/' . $normalized
            : '@app/' . $fallbackDirectory . '/' . $normalized;
    }
}
