<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

interface TemplateLoaderInterface
{
    /**
     * Register or replace a namespace definition.
     *
     * @param string $namespace Namespace name (without leading @)
     * @param \Sugar\Core\Loader\TemplateNamespaceDefinition $definition Namespace definition
     */
    public function registerNamespace(string $namespace, TemplateNamespaceDefinition $definition): void;

    /**
     * Get all registered namespace names.
     *
     * @return array<string> Namespace names (e.g. ['app', 'auth-plugin', 'reports'])
     */
    public function getRegisteredNamespaces(): array;

    /**
     * Load a template source by logical name.
     *
     * Names are canonicalized through resolve() before loading.
     *
     * @param string $name Logical template name (e.g. "@app/pages/home" or "pages/home")
     * @return string Template source
     * @throws \Sugar\Core\Exception\TemplateNotFoundException If template is not found
     */
    public function load(string $name): string;

    /**
     * Check whether a logical template exists.
     *
     * @param string $name Logical template name
     * @return bool True when a source can be loaded
     */
    public function exists(string $name): bool;

    /**
     * Resolve a name relative to a referring template into canonical form.
     *
     * Canonical names always include an explicit namespace, e.g. "@app/pages/home".
     *
     * @param string $name Logical name to resolve
     * @param string $referrer Canonical or logical referring template name
     * @return string Canonical template name
     */
    public function resolve(string $name, string $referrer = ''): string;

    /**
     * Return a stable source identity for caching and dependency graphs.
     *
     * Implementations should return a deterministic identifier. For filesystem
     * sources this is typically the absolute path.
     *
     * @param string $name Logical template name
     * @return string Stable source identifier
     */
    public function sourceId(string $name): string;

    /**
     * Resolve to a physical source path when one exists.
     *
     * Returns null for non-filesystem backends.
     *
     * @param string $name Logical template name
     * @return string|null Physical source path
     */
    public function sourcePath(string $name): ?string;

    /**
     * Discover templates in a namespace.
     *
     * Returned names are canonical (with "@namespace/").
     *
     * @param string $namespace Namespace name
     * @param string $prefix Optional prefix within the namespace
     * @return array<string>
     */
    public function discover(string $namespace, string $prefix = ''): array;
}
