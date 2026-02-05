<?php
declare(strict_types=1);

namespace Sugar\Runtime;

use Countable;
use Traversable;

/**
 * Helper utilities for empty checks in template directives
 *
 * Provides smart empty checking that works with:
 * - Scalars (strings, numbers, booleans)
 * - Arrays
 * - Countable objects (Collections, ArrayObject, SplFixedArray)
 * - Traversable objects (Generators, Iterators)
 * - Objects (always non-empty unless Countable/Traversable)
 *
 * Used by s:empty, s:forelse, and other conditional directives.
 */
final class EmptyHelper
{
    /**
     * Check if a value is empty
     *
     * Empty rules:
     * - null, false, '', '0', 0, 0.0: empty
     * - Arrays: empty if count === 0
     * - Countable: empty if count() === 0
     * - Traversable: empty if no items (WARNING: consumes generators!)
     * - Objects: NOT empty (unless Countable/Traversable says so)
     *
     * Examples:
     * ```php
     * EmptyHelper::isEmpty([]);                    // true
     * EmptyHelper::isEmpty('');                    // true
     * EmptyHelper::isEmpty(0);                     // true
     * EmptyHelper::isEmpty(new ArrayObject([]));   // true (Countable)
     * EmptyHelper::isEmpty($collection);           // checks count()
     * EmptyHelper::isEmpty($user);                 // false (object)
     * EmptyHelper::isEmpty($generator);            // checks iteration
     * ```
     *
     * @param mixed $value Value to check
     * @return bool True if value is considered empty
     */
    public static function isEmpty(mixed $value): bool
    {
        // Handle standard PHP empty cases for scalars
        if ($value === null || $value === false || $value === '' || $value === '0' || $value === 0 || $value === 0.0) {
            return true;
        }

        // Handle arrays - use native empty()
        if (is_array($value)) {
            return empty($value);
        }

        // Handle Countable objects (Collections, ArrayObject, SplFixedArray, etc.)
        if ($value instanceof Countable) {
            return $value->count() === 0;
        }

        // Handle Traversable (Generators, Iterators, etc.)
        if ($value instanceof Traversable) {
            // Check if iterator has at least one item
            // WARNING: This consumes generators - they can't be reused
            foreach ($value as $_) {
                return false; // Has at least one item
            }

            return true; // No items found
        }

        // All other values (objects, resources, etc.) are considered non-empty
        return false;
    }

    /**
     * Ensure value is iterable for foreach loops
     *
     * Converts non-iterable values to empty arrays to prevent foreach errors.
     * Used internally by foreach-based directives.
     *
     * @param mixed $value Value to convert
     * @return iterable<array-key, mixed> Iterable value (array or Traversable)
     */
    public static function ensureIterable(mixed $value): iterable
    {
        if (is_array($value) || $value instanceof Traversable) {
            return $value;
        }

        // Return empty array for non-iterable values
        return [];
    }
}
