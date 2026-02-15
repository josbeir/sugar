<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

interface TemplateLoaderInterface
{
    /**
     * Load a template by its path.
     *
     * @param string $path Template path
     * @return string Template content
     * @throws \Sugar\Core\Exception\TemplateNotFoundException If template is not found
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
     * Resolve a template path to an absolute filesystem path.
     *
     * @param string $path Path to resolve
     * @param string $currentTemplate Current template path (for relative resolution)
     * @return string Absolute filesystem path when available
     */
    public function resolveToFilePath(string $path, string $currentTemplate = ''): string;

    /**
     * List known logical template paths.
     *
     * Returned paths are normalized and relative to the loader root namespace
     * (e.g. "components/s-button.sugar.php").
     *
     * @param string $pathPrefix Optional normalized prefix filter
     * @return array<string>
     */
    public function listTemplatePaths(string $pathPrefix = ''): array;
}
