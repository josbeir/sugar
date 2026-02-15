<?php
declare(strict_types=1);

namespace Sugar\Core\Loader;

interface ResourceLocatorInterface
{
    /**
     * Register a resource type definition.
     */
    public function registerType(ResourceTypeDefinition $definition): void;

    /**
     * Check whether a named resource exists in a type.
     */
    public function has(string $type, string $name): bool;

    /**
     * Resolve a named resource to its logical template path.
     */
    public function path(string $type, string $name): string;

    /**
     * Resolve a named resource to its source file path.
     */
    public function filePath(string $type, string $name): string;

    /**
     * Load a named resource source.
     */
    public function load(string $type, string $name): string;
}
