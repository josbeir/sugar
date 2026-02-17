<?php
declare(strict_types=1);

namespace Sugar\Core\Cache;

use Sugar\Core\Util\Hash;

/**
 * Utility for composing and parsing template cache keys.
 */
final class CacheKey
{
    /**
     * Delimiter separating base template key and block-variant hash.
     */
    private const BLOCKS_DELIMITER = '::blocks:';

    /**
     * Build a cache key for a template and optional block selection.
     *
     * @param string $templateKey Canonical template key
     * @param array<string>|null $blocks Optional selected block names
     * @return string Cache key including variant segment when blocks are provided
     */
    public static function fromTemplate(string $templateKey, ?array $blocks = null): string
    {
        if ($blocks === null) {
            return $templateKey;
        }

        return $templateKey . self::BLOCKS_DELIMITER . Hash::make((string)json_encode($blocks));
    }

    /**
     * Strip variant information from a cache key.
     *
     * @param string $cacheKey Full cache key
     * @return string Base template key without block-variant suffix
     */
    public static function baseTemplateKey(string $cacheKey): string
    {
        $delimiterPosition = strpos($cacheKey, self::BLOCKS_DELIMITER);
        if ($delimiterPosition === false) {
            return $cacheKey;
        }

        return substr($cacheKey, 0, $delimiterPosition);
    }
}
