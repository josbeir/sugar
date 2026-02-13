<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Stub;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Lightweight in-memory PSR-16 cache implementation for tests.
 */
final class ArraySimpleCache implements CacheInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * @var array<string, null|int|\DateInterval>
     */
    private array $ttls = [];

    /**
     * @inheritDoc
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): bool
    {
        unset($this->values[$key], $this->ttls[$key]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        $this->values = [];
        $this->ttls = [];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $keyString = (string)$key;
            $result[$keyString] = $this->get($keyString, $default);
        }

        return $result;
    }

    /**
     * Set multiple key-value pairs in cache.
     *
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string)$key);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * Get raw stored values for assertions.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Get captured TTL values for assertions.
     *
     * @return array<string, null|int|\DateInterval>
     */
    public function ttls(): array
    {
        return $this->ttls;
    }
}
