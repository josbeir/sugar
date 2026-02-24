<?php
declare(strict_types=1);

namespace Sugar\Core\Runtime;

use Sugar\Core\Escape\Escaper;
use Sugar\Core\Util\ValueNormalizer;

/**
 * Utility class for HTML attribute manipulation at runtime
 *
 * Provides helpers for conditional classes and attribute spreading
 * used in compiled templates.
 *
 * Note: For compile-time AST attribute operations, see \Sugar\Core\Ast\Helper\AttributeHelper
 */
final class HtmlAttributeHelper
{
    /**
     * Build a complete class attribute string from any class value input.
     *
     * Returns an empty string when the resolved class list is empty so callers
     * can omit the attribute entirely.
     *
     * @param mixed $classValue Class input (string|array|Stringable|bool|null)
     * @return string Complete class attribute or empty string
     */
    public static function classAttribute(mixed $classValue): string
    {
        $resolvedClassValue = self::normalizeClassValue($classValue);
        if ($resolvedClassValue === '') {
            return '';
        }

        return sprintf('class="%s"', Escaper::attr($resolvedClassValue));
    }

    /**
     * Merge existing and incoming class values into a single class string.
     *
     * Both values can be arrays, strings, booleans, null, or stringable objects.
     * Empty results are removed to avoid extra whitespace.
     *
     * @param mixed $existing Existing class value
     * @param mixed $incoming Incoming class value
     * @return string Merged class list
     */
    public static function mergeClassValues(mixed $existing, mixed $incoming): string
    {
        $existingClassValue = self::normalizeClassValue($existing);
        $incomingClassValue = self::normalizeClassValue($incoming);

        return implode(
            ' ',
            array_filter(
                [$existingClassValue, $incomingClassValue],
                static fn(string $value): bool => $value !== '',
            ),
        );
    }

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
     * Normalize mixed class input into a single space-separated class string.
     *
     * @param mixed $value Class input to normalize
     * @return string Normalized class string
     */
    private static function normalizeClassValue(mixed $value): string
    {
        if (is_array($value)) {
            return self::classNames($value);
        }

        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return '1';
        }

        return trim(ValueNormalizer::toDisplayString($value));
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
