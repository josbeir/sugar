<?php
declare(strict_types=1);

namespace Sugar\Util;

/**
 * Array normalization helpers
 *
 * Centralizes common array cleanup patterns used across the engine.
 */
final class ArrayHelper
{
    /**
     * Normalize a list of strings to a stable, trimmed, unique array.
     *
     * @param array<mixed>|null $values
     * @return array<string>|null
     */
    public static function normalizeStringList(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn(string $value): string => trim($value),
            array_filter($values, 'is_string'),
        ), static fn(string $value): bool => $value !== '')));

        if ($normalized === []) {
            return null;
        }

        sort($normalized);

        return $normalized;
    }
}
