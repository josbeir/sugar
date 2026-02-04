<?php
declare(strict_types=1);

namespace Sugar;

use Sugar\Extension\ExtensionRegistry;

/**
 * Template compiler interface
 */
interface CompilerInterface
{
    /**
     * Get the extension registry for framework customization
     *
     * Allows frameworks to register custom directives, components, etc.
     */
    public function getExtensionRegistry(): ExtensionRegistry;

    /**
     * Compile template source to executable PHP code
     *
     * @param string $source Template source code
     * @param string|null $templatePath Template path for inheritance resolution and debug info (default: null)
     * @param bool $debug Enable debug mode with inline source comments (default: false)
     * @return string Compiled PHP code
     */
    public function compile(
        string $source,
        ?string $templatePath = null,
        bool $debug = false,
    ): string;
}
