<?php
declare(strict_types=1);

namespace Sugar\Runtime;

use Psr\SimpleCache\CacheInterface;
use Sugar\Exception\TemplateRuntimeException;

/**
 * Runtime environment for template execution
 */
final class RuntimeEnvironment
{
    private static ?ComponentRenderer $renderer = null;

    private static ?CacheInterface $fragmentCache = null;

    /**
     * Register runtime services for the current template execution.
     */
    public static function set(ComponentRenderer $renderer, ?CacheInterface $fragmentCache = null): void
    {
        self::$renderer = $renderer;
        self::$fragmentCache = $fragmentCache;
    }

    /**
     * Clear all runtime services for the current template execution.
     */
    public static function clear(): void
    {
        self::$renderer = null;
        self::$fragmentCache = null;
    }

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

    /**
     * Register a fragment cache store for the current runtime.
     */
    public static function setFragmentCache(?CacheInterface $fragmentCache): void
    {
        self::$fragmentCache = $fragmentCache;
    }

    /**
     * Remove the current fragment cache store.
     */
    public static function clearFragmentCache(): void
    {
        self::$fragmentCache = null;
    }

    /**
     * Get the active fragment cache store, if any.
     */
    public static function getFragmentCache(): ?CacheInterface
    {
        return self::$fragmentCache;
    }
}
