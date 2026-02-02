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
     * @return string Compiled PHP code
     */
    public function compile(string $source): string;
}
