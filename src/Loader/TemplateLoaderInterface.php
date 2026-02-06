<?php
declare(strict_types=1);

namespace Sugar\Loader;

interface TemplateLoaderInterface
{
    /**
     * Load a template by its path.
     *
     * @param string $path Template path
     * @return string Template content
     * @throws \Sugar\Exception\TemplateNotFoundException If template is not found
     */
    public function load(string $path): string;

    /**
     * Resolve a template path relative to a current template.
     *
     * @param string $path Path to resolve
     * @param string $currentTemplate Current template path (for relative resolution)
     * @return string Resolved path
     */
    public function resolve(string $path, string $currentTemplate = ''): string;

    /**
     * Load a component template by name.
     *
     * @param string $name Component name (without prefix, e.g., "button" for "s-button")
     * @return string Component template content
     * @throws \Sugar\Exception\ComponentNotFoundException If component is not found
     */
    public function loadComponent(string $name): string;
}
