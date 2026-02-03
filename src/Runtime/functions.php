<?php
declare(strict_types=1);

/**
 * Sugar Runtime Functions
 *
 * These functions provide runtime fallbacks for features that are typically
 * handled at compile-time by the parser. They ensure templates work correctly
 * even if the parser detection fails.
 */

namespace Sugar\Runtime;

if (!function_exists('Sugar\Runtime\raw')) {
    /**
     * Output raw/unescaped content
     *
     * Disables auto-escaping for trusted content. The parser detects this
     * function at compile-time and unwraps the expression.
     *
     * WARNING: Only use with trusted content to prevent XSS attacks.
     *
     * @param mixed $value The value to output without escaping
     * @return mixed The same value, unmodified
     */
    function raw(mixed $value): mixed
    {
        return $value;
    }
}

if (!function_exists('Sugar\Runtime\r')) {
    /**
     * Short alias for raw() function
     *
     * Convenience alias for raw() - outputs content without escaping.
     *
     * WARNING: Only use with trusted content to prevent XSS attacks.
     *
     * @param mixed $value The value to output without escaping
     * @return mixed The same value, unmodified
     */
    function r(mixed $value): mixed
    {
        return $value;
    }
}
