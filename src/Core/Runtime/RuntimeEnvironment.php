<?php
declare(strict_types=1);

namespace Sugar\Core\Runtime;

use Sugar\Core\Exception\TemplateRuntimeException;

/**
 * Runtime environment for template execution
 */
final class RuntimeEnvironment
{
    /**
     * Runtime service id for the component renderer.
     */
    public const RENDERER_SERVICE_ID = 'renderer.component';

    /**
     * @var array<string, mixed>
     */
    private static array $services = [];

    /**
     * Register runtime services for the current template execution.
     *
     * @param array<string, mixed> $services Runtime services keyed by identifier
     */
    public static function set(array $services = []): void
    {
        self::$services = $services;
    }

    /**
     * Clear all runtime services for the current template execution.
     */
    public static function clear(): void
    {
        self::$services = [];
    }

    /**
     * Register a named runtime service.
     *
     * @param string $id Service identifier
     * @param mixed $service Service value
     */
    public static function setService(string $id, mixed $service): void
    {
        self::$services[$id] = $service;
    }

    /**
     * Remove a named runtime service.
     *
     * @param string $id Service identifier
     */
    public static function clearService(string $id): void
    {
        unset(self::$services[$id]);
    }

    /**
     * Determine whether a named runtime service exists.
     *
     * @param string $id Service identifier
     * @return bool True when service is registered
     */
    public static function hasService(string $id): bool
    {
        return array_key_exists($id, self::$services);
    }

    /**
     * Fetch a named runtime service.
     *
     * @param string $id Service identifier
     * @return mixed Service value, or null when missing
     */
    public static function getService(string $id): mixed
    {
        return self::$services[$id] ?? null;
    }

    /**
     * Fetch a named runtime service and fail when it is missing.
     *
     * @param string $id Service identifier
     * @return mixed Service value
     * @throws \Sugar\Core\Exception\TemplateRuntimeException When service is not initialized
     */
    public static function requireService(string $id): mixed
    {
        if (!array_key_exists($id, self::$services)) {
            throw new TemplateRuntimeException(sprintf('Runtime service "%s" is not initialized.', $id));
        }

        return self::$services[$id];
    }
}
