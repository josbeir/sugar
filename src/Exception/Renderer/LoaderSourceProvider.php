<?php
declare(strict_types=1);

namespace Sugar\Exception\Renderer;

use Sugar\Exception\SugarException;
use Sugar\Loader\TemplateLoaderInterface;
use Throwable;

/**
 * Loads template source using a template loader.
 */
final class LoaderSourceProvider implements SourceProviderInterface
{
    /**
     * @param \Sugar\Loader\TemplateLoaderInterface $loader Template loader
     */
    public function __construct(private readonly TemplateLoaderInterface $loader)
    {
    }

    /**
     * @inheritDoc
     */
    public function getSource(SugarException $exception): ?string
    {
        if ($exception->templatePath === null) {
            return null;
        }

        try {
            return $this->loader->load($exception->templatePath);
        } catch (Throwable) {
            return null;
        }
    }
}
