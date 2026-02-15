<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Loader;

/**
 * Contract for loading component templates within the component extension.
 *
 * Keeping this interface in the extension prevents component-specific
 * responsibilities from leaking into core template loading contracts.
 */
interface ComponentLoaderInterface
{
    /**
     * Load a component template by name.
     *
     * @param string $name Component name (without prefix, e.g., "button" for "s-button")
     * @return string Component template content
     */
    public function loadComponent(string $name): string;

    /**
     * Resolve a component name to its logical template path.
     *
     * @param string $name Component name (without prefix)
     * @return string Component path for diagnostics and relative resolution
     */
    public function getComponentPath(string $name): string;

    /**
     * Resolve a component name to an absolute or stable source path.
     *
     * @param string $name Component name (without prefix)
     * @return string Component source file path
     */
    public function getComponentFilePath(string $name): string;
}
