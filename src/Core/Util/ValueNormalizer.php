<?php
declare(strict_types=1);

namespace Sugar\Core\Util;

use Stringable;
use Traversable;

/**
 * Centralized value normalization helpers for runtime rendering.
 *
 * Provides a single source of truth for converting mixed template values into
 * display strings, iterable values, and spread-attribute-safe values.
 */
final class ValueNormalizer
{
    /**
     * Normalize any renderable value to a display string.
     *
     * Rules:
     * - null => ''
     * - string/scalar/Stringable => cast to string
     * - all other values => ''
     */
    public static function toDisplayString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string)$value;
        }

        return '';
    }

    /**
     * Normalize mixed value to iterable content.
     *
     * Returns arrays/traversables as-is, otherwise returns an empty array.
     *
     * @return iterable<mixed>
     */
    public static function toIterable(mixed $value): iterable
    {
        if (is_array($value) || $value instanceof Traversable) {
            return $value;
        }

        return [];
    }

    /**
     * Normalize a value for spread attribute rendering.
     *
     * Rules:
     * - null, bool, int, float, string => keep as-is
     * - Stringable => cast to string
     * - all other values => null (attribute omitted)
     */
    public static function toAttributeValue(mixed $value): bool|int|float|string|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string)$value;
        }

        return null;
    }

    /**
     * Normalize string list input into a sorted, unique, non-empty list.
     *
     * This helper is intentionally strict and only keeps string values.
     *
     * @param array<mixed>|null $values
     * @return array<string>|null
     */
    public static function normalizeStringList(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        if ($normalized === []) {
            return null;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }
}
