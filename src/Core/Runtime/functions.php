<?php
declare(strict_types=1);

/**
 * Sugar Runtime Functions
 *
 * These functions provide runtime fallbacks for features that are typically
 * handled at compile-time by the parser. They ensure templates work correctly
 * even if the parser detection fails.
 */

namespace Sugar\Core\Runtime;

use Sugar\Core\Escape\Escaper;

if (!function_exists('Sugar\Core\Runtime\raw')) {
    /**
     * Output raw/unescaped content
     *
     * Disables auto-escaping for trusted content. The parser detects this
     * function at compile-time and unwraps the expression.
     *
     * WARNING: Only use with trusted content to prevent XSS attacks.
     *
     * @param mixed|null $value The value to output without escaping
     * @return mixed The same value, unmodified
     */
    function raw(mixed $value = null): mixed
    {
        return $value;
    }
}

if (!function_exists('Sugar\Core\Runtime\json')) {
    /**
     * Output JSON-encoded content
     *
     * The parser detects this function at compile-time and replaces it with
     * context-aware escaping. This runtime stub keeps templates functional
     * if parser detection fails.
     *
     * @param mixed|null $value The value to encode as JSON
     * @return string JSON-encoded value
     */
    function json(mixed $value = null): string
    {
        return Escaper::json($value);
    }
}
