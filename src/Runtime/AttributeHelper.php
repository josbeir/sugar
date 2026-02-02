<?php
declare(strict_types=1);

namespace Sugar\Runtime;

/**
 * Utility class for HTML attribute manipulation
 *
 * Provides helpers for conditional classes and attribute spreading
 */
final class AttributeHelper
{
    /**
     * Generate class attribute string from conditional array
     *
     * Handles both numeric and associative arrays:
     * - Numeric keys: Include value if truthy
     * - Associative keys: Include key if value is truthy
     *
     * Examples:
     * ```php
     * AttributeHelper::classNames(['btn', 'btn-primary'])
     * // → "btn btn-primary"
     *
     * AttributeHelper::classNames(['btn', 'active' => true, 'disabled' => false])
     * // → "btn active"
     *
     * AttributeHelper::classNames(['card', '', 'shadow' => $hasShadow])
     * // → "card shadow" (if $hasShadow is true)
     * ```
     *
     * @param array<string|int, mixed> $classes Array of class names and conditions
     * @return string Space-separated class names
     */
    public static function classNames(array $classes): string
    {
        $result = [];

        foreach ($classes as $key => $value) {
            if (is_int($key)) {
                // Numeric key: include value if truthy
                if (!empty($value) && is_string($value)) {
                    $result[] = $value;
                }
            } else {
                // Associative key: include key if value is truthy
                if ($value) {
                    $result[] = $key;
                }
            }
        }

        return implode(' ', array_filter($result));
    }

    /**
     * Spread attributes array into HTML attribute string
     *
     * Handles various attribute types:
     * - Boolean true: Output attribute name only (e.g., disabled)
     * - Boolean false or null: Omit attribute
     * - Regular values: Output key="value" with HTML escaping
     *
     * Examples:
     * ```php
     * AttributeHelper::spreadAttrs(['id' => 'user-123', 'disabled' => true])
     * // → 'id="user-123" disabled'
     *
     * AttributeHelper::spreadAttrs(['hidden' => false, 'data-value' => 'test'])
     * // → 'data-value="test"'
     * ```
     *
     * @param array<string, mixed> $attributes Associative array of attributes
     * @return string Space-separated HTML attributes
     */
    public static function spreadAttrs(array $attributes): string
    {
        $result = [];

        foreach ($attributes as $key => $value) {
            // Skip false and null values
            if ($value === null || $value === false) {
                continue;
            }

            // Boolean true: output attribute name only
            if ($value === true) {
                $result[] = htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                continue;
            }

            // Regular attribute: key="value"
            $escapedKey = htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $escapedValue = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $result[] = sprintf('%s="%s"', $escapedKey, $escapedValue);
        }

        return implode(' ', $result);
    }
}
