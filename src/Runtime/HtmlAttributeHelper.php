<?php
declare(strict_types=1);

namespace Sugar\Runtime;

use Sugar\Escape\Escaper;
use Sugar\Util\ValueNormalizer;

/**
 * Utility class for HTML attribute manipulation at runtime
 *
 * Provides helpers for conditional classes and attribute spreading
 * used in compiled templates.
 *
 * Note: For compile-time AST attribute operations, see \Sugar\Ast\Helper\AttributeHelper
 */
final class HtmlAttributeHelper
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
                // Numeric key: include non-empty stringable value
                $className = trim(ValueNormalizer::toDisplayString($value));
                if ($className !== '') {
                    $result[] = $className;
                }
            } elseif ($value) {
                // Associative key: include key if value is truthy
                $result[] = $key;
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
            $value = ValueNormalizer::toAttributeValue($value);

            // Skip false and null values
            if ($value === null) {
                continue;
            }

            if ($value === false) {
                continue;
            }

            // Boolean true: output attribute name only
            if ($value === true) {
                $result[] = Escaper::attr($key);
                continue;
            }

            // Regular attribute: key="value"
            $escapedKey = Escaper::attr($key);
            $escapedValue = Escaper::attr(ValueNormalizer::toDisplayString($value));
            $result[] = sprintf('%s="%s"', $escapedKey, $escapedValue);
        }

        return implode(' ', $result);
    }

    /**
     * Conditionally output a boolean HTML attribute
     *
     * Boolean attributes are attributes whose presence represents true
     * and absence represents false (e.g., checked, disabled, selected).
     *
     * Examples:
     * ```php
     * HtmlAttributeHelper::booleanAttribute('checked', true)
     * // → "checked"
     *
     * HtmlAttributeHelper::booleanAttribute('checked', false)
     * // → ""
     *
     * HtmlAttributeHelper::booleanAttribute('disabled', $isProcessing)
     * // → "disabled" if $isProcessing is truthy, "" otherwise
     * ```
     *
     * @param string $name Attribute name
     * @param mixed $condition Condition to check
     * @return string Attribute name if condition is truthy, empty string otherwise
     */
    public static function booleanAttribute(string $name, mixed $condition): string
    {
        return $condition ? $name : '';
    }
}
