<?php
declare(strict_types=1);

namespace Sugar\Runtime;

use Sugar\Exception\TemplateRuntimeException;

/**
 * Runtime environment for template execution
 */
final class RuntimeEnvironment
{
    private static ?ComponentRenderer $renderer = null;

    /**
     * Register a component renderer for the current runtime
     */
    public static function setRenderer(ComponentRenderer $renderer): void
    {
        self::$renderer = $renderer;
    }

    /**
     * Remove the current renderer
     */
    public static function clearRenderer(): void
    {
        self::$renderer = null;
    }

    /**
     * Get the active component renderer
     */
    public static function getRenderer(): ComponentRenderer
    {
        if (!self::$renderer instanceof ComponentRenderer) {
            throw new TemplateRuntimeException('Component renderer is not initialized.');
        }

        return self::$renderer;
    }
}
