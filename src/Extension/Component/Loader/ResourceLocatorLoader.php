<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Loader;

use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\TemplateNotFoundException;
use Sugar\Core\Loader\ResourceLocator;
use Sugar\Core\Loader\ResourceLocatorInterface;
use Sugar\Core\Loader\ResourceTypeDefinition;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;

/**
 * Component loader that delegates lookups to the core ResourceLocator.
 */
final readonly class ResourceLocatorLoader implements ComponentLoaderInterface
{
    private const TYPE = 'component';

    /**
     * @param \Sugar\Core\Loader\ResourceLocatorInterface $locator Core resource locator
     */
    public function __construct(private ResourceLocatorInterface $locator)
    {
    }

    /**
     * Create a component loader from a core template loader.
     *
     * @param array<string> $directories Component directories
     */
    public static function forTemplateLoader(
        TemplateLoaderInterface $templateLoader,
        SugarConfig $config,
        array $directories = ['components'],
    ): self {
        $locator = new ResourceLocator($templateLoader, $config);
        $locator->registerType(new ResourceTypeDefinition(
            name: self::TYPE,
            directories: $directories,
            stripElementPrefix: true,
            ignoreFragmentElement: true,
        ));

        return new self($locator);
    }

    /**
     * @inheritDoc
     */
    public function loadComponent(string $name): string
    {
        try {
            return $this->locator->load(self::TYPE, $name);
        } catch (TemplateNotFoundException $templateNotFoundException) {
            throw new ComponentNotFoundException(
                $templateNotFoundException->getMessage(),
                previous: $templateNotFoundException,
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getComponentPath(string $name): string
    {
        try {
            return $this->locator->path(self::TYPE, $name);
        } catch (TemplateNotFoundException $templateNotFoundException) {
            throw new ComponentNotFoundException(
                $templateNotFoundException->getMessage(),
                previous: $templateNotFoundException,
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getComponentFilePath(string $name): string
    {
        try {
            return $this->locator->filePath(self::TYPE, $name);
        } catch (TemplateNotFoundException $templateNotFoundException) {
            throw new ComponentNotFoundException(
                $templateNotFoundException->getMessage(),
                previous: $templateNotFoundException,
            );
        }
    }
}
