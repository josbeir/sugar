<?php
declare(strict_types=1);

namespace Sugar\Runtime;

use Countable;
use Generator;
use Sugar\Exception\GeneratorNotSupportedException;
use Traversable;

/**
 * Helper utilities for empty checks in template directives
 *
 * Provides smart empty checking that works with:
 * - Scalars (strings, numbers, booleans)
 * - Arrays
 * - Countable objects (Collections, ArrayObject, SplFixedArray)
 * - Traversable objects (Iterators - NOT Generators)
 * - Objects (always non-empty unless Countable/Traversable)
 *
 * Note: Generators are NOT supported - they must be converted to arrays
 * before use with s:empty or s:forelse directives.
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
     * - Traversable: empty if no items (Iterators only, NOT Generators)
     * - Objects: NOT empty (unless Countable/Traversable says so)
     *
     * Examples:
     * ```php
     * EmptyHelper::isEmpty([]);                    // true
     * EmptyHelper::isEmpty('');                    // true
     * EmptyHelper::isEmpty(0);                     // true
     * EmptyHelper::isEmpty(new ArrayObject([]));   // true (Countable)
     * EmptyHelper::isEmpty($collection);           // checks count()
     * EmptyHelper::isEmpty($iterator);             // checks iteration
     * EmptyHelper::isEmpty($user);                 // false (object)
     * EmptyHelper::isEmpty($generator);            // throws GeneratorNotSupportedException
     * ```
     *
     * @param mixed $value Value to check
     * @return bool True if value is considered empty
     * @throws \Sugar\Exception\GeneratorNotSupportedException When a Generator is passed
     */
    public static function isEmpty(mixed $value): bool
    {
        // Handle standard PHP empty cases for scalars
        if (in_array($value, [null, false, '', '0', 0, 0.0], true)) {
            return true;
        }

        // Handle arrays - use native empty()
        if (is_array($value)) {
            return $value === [];
        }

        // Handle Countable objects (Collections, ArrayObject, SplFixedArray, etc.)
        if ($value instanceof Countable) {
            return $value->count() === 0;
        }

        // Generators cannot be checked without consuming them
        if ($value instanceof Generator) {
            throw new GeneratorNotSupportedException();
        }

        // Handle Traversable (Iterators, etc.)
        if ($value instanceof Traversable) {
            // Check if iterator has at least one item
            foreach ($value as $_) {
                return false; // Has at least one item
            }

            return true; // No items found
        }

        // All other values (objects, resources, etc.) are considered non-empty
        return false;
    }
}
