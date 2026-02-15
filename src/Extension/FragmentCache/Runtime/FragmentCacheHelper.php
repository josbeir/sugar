<?php
declare(strict_types=1);

namespace Sugar\Extension\FragmentCache\Runtime;

use Psr\SimpleCache\CacheInterface;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Core\Util\ValueNormalizer;
use Throwable;

/**
 * Runtime helper for fragment-level caching used by the s:cache directive.
 */
final class FragmentCacheHelper
{
    /**
     * Runtime service id for PSR-16 fragment cache store.
     */
    public const SERVICE_ID = 'cache.fragment';

    /**
     * Internal key namespace for fragment cache entries.
     */
    private const KEY_PREFIX = 'sugar_fragment:';

    /**
     * Resolve a cache key from directive options.
     *
     * @param mixed $options Directive expression result
     * @param string $fallbackKey Generated fallback key when no explicit key is provided
     * @return string Cache key to use
     */
    public static function resolveKey(mixed $options, string $fallbackKey): string
    {
        if (is_array($options) && array_key_exists('key', $options)) {
            return self::normalizeKey($options['key'], $fallbackKey);
        }

        if ($options === null || $options === true) {
            return $fallbackKey;
        }

        return self::normalizeKey($options, $fallbackKey);
    }

    /**
     * Resolve a TTL value (seconds) from directive options.
     *
     * @param mixed $options Directive expression result
     * @param int|null $defaultTtl Default TTL in seconds, or null to pass null to the PSR-16 store
     * @return int|null Non-negative TTL in seconds, or null to pass null to the PSR-16 store
     */
    public static function resolveTtl(mixed $options, ?int $defaultTtl = null): ?int
    {
        $ttl = is_int($defaultTtl) && $defaultTtl >= 0 ? $defaultTtl : null;

        if (is_array($options) && array_key_exists('ttl', $options)) {
            $ttlCandidate = $options['ttl'];
            if (is_int($ttlCandidate)) {
                $ttl = $ttlCandidate >= 0 ? $ttlCandidate : $ttl;
            } elseif (is_string($ttlCandidate) && is_numeric($ttlCandidate)) {
                $parsedTtl = (int)$ttlCandidate;
                $ttl = $parsedTtl >= 0 ? $parsedTtl : $ttl;
            }
        }

        return $ttl;
    }

    /**
     * Fetch cached fragment content.
     *
     * @param string $key Cache key
     * @return string|null Cached content, or null when missing/unavailable
     */
    public static function get(string $key): ?string
    {
        $store = self::getStore();
        if (!$store instanceof CacheInterface) {
            return null;
        }

        try {
            $value = $store->get(self::KEY_PREFIX . $key);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Store rendered fragment content.
     *
     * @param string $key Cache key
     * @param string $content Rendered fragment content
     * @param int|null $ttl Positive TTL in seconds, or null for no expiry
     */
    public static function set(string $key, string $content, ?int $ttl = null): void
    {
        $store = self::getStore();
        if (!$store instanceof CacheInterface) {
            return;
        }

        try {
            $store->set(self::KEY_PREFIX . $key, $content, $ttl);
        } catch (Throwable) {
            // Cache failures should not break template rendering.
        }
    }

    /**
     * Normalize an explicit key candidate to a non-empty cache key.
     *
     * @param mixed $value Explicit key candidate from directive options
     * @param string $fallbackKey Generated fallback key
     * @return string Normalized key
     */
    private static function normalizeKey(mixed $value, string $fallbackKey): string
    {
        $key = trim(ValueNormalizer::toDisplayString($value));

        return $key !== '' ? $key : $fallbackKey;
    }

    /**
     * Resolve the configured fragment cache runtime service.
     */
    private static function getStore(): ?CacheInterface
    {
        $store = RuntimeEnvironment::getService(self::SERVICE_ID);

        return $store instanceof CacheInterface ? $store : null;
    }
}
