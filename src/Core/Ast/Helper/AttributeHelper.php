<?php
declare(strict_types=1);

namespace Sugar\Core\Ast\Helper;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\OutputNode;

/**
 * Helper for AST attribute operations during compilation
 *
 * Provides utilities for finding, filtering, and manipulating attributes
 * on AST nodes during the compilation process.
 *
 * Note: This is for compile-time AST manipulation. For runtime HTML attribute
 * helpers, see \Sugar\Core\Runtime\AttributeHelper.
 */
final class AttributeHelper
{
    /**
     * Find attribute by exact name match
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Attributes to search
     * @param string $name Attribute name to find
     * @return \Sugar\Core\Ast\AttributeNode|null Found attribute or null
     */
    public static function findAttribute(array $attributes, string $name): ?AttributeNode
    {
        foreach ($attributes as $attr) {
            if ($attr->name === $name) {
                return $attr;
            }
        }

        return null;
    }

    /**
     * Find all attributes matching a prefix
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Attributes to search
     * @param string $prefix Prefix to match (e.g., 's:', 's-bind:')
     * @return array<\Sugar\Core\Ast\AttributeNode> Matching attributes
     */
    public static function findAttributesByPrefix(array $attributes, string $prefix): array
    {
        $matches = [];

        foreach ($attributes as $attr) {
            if (str_starts_with($attr->name, $prefix)) {
                $matches[] = $attr;
            }
        }

        return $matches;
    }

    /**
     * Check if node has attribute with given name
     *
     * @param \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode $node Node to check
     * @param string $name Attribute name
     */
    public static function hasAttribute(ElementNode|FragmentNode $node, string $name): bool
    {
        return self::findAttribute($node->attributes, $name) instanceof AttributeNode;
    }

    /**
     * Check if node has any attribute matching prefix
     *
     * @param \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode $node Node to check
     * @param string $prefix Prefix to match
     */
    public static function hasAttributeWithPrefix(ElementNode|FragmentNode $node, string $prefix): bool
    {
        return self::findAttributesByPrefix($node->attributes, $prefix) !== [];
    }

    /**
     * Get attribute value or return default
     *
     * @param \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode $node Node to get attribute from
     * @param string $name Attribute name
     * @param \Sugar\Core\Ast\AttributeValue|null $default Default value if attribute not found
     * @return \Sugar\Core\Ast\AttributeValue|null Attribute value or default
     */
    public static function getAttributeValue(
        ElementNode|FragmentNode $node,
        string $name,
        ?AttributeValue $default = null,
    ): ?AttributeValue {
        $attr = self::findAttribute($node->attributes, $name);

        return $attr instanceof AttributeNode ? $attr->value : $default;
    }

    /**
     * Get attribute value as string or return default.
     *
     * @param \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode $node Node to get attribute from
     * @param string $name Attribute name
     * @param string $default Default value if attribute not found or not a string
     */
    public static function getStringAttributeValue(
        ElementNode|FragmentNode $node,
        string $name,
        string $default = '',
    ): string {
        $value = self::getAttributeValue($node, $name);
        if (!$value instanceof AttributeValue) {
            return $default;
        }

        return $value->isStatic() ? ($value->static ?? $default) : $default;
    }

    /**
     * Remove attribute by name (returns new array)
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Attributes array
     * @param string $name Attribute name to remove
     * @return array<\Sugar\Core\Ast\AttributeNode> New array without the attribute
     */
    public static function removeAttribute(array $attributes, string $name): array
    {
        return array_values(array_filter(
            $attributes,
            fn(AttributeNode $attr): bool => $attr->name !== $name,
        ));
    }

    /**
     * Remove attributes matching predicate (returns new array)
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Attributes array
     * @param callable(\Sugar\Core\Ast\AttributeNode): bool $predicate Filter function
     * @return array<\Sugar\Core\Ast\AttributeNode> New filtered array
     */
    public static function filterAttributes(array $attributes, callable $predicate): array
    {
        return array_values(array_filter($attributes, $predicate));
    }

    /**
     * Find attribute index by name
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Attributes to search
     * @param string $name Attribute name
     * @return int|null Index or null if not found
     */
    public static function findAttributeIndex(array $attributes, string $name): ?int
    {
        foreach ($attributes as $index => $attr) {
            if ($attr->name === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Find attribute and its index by name
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Attributes to search
     * @param string $name Attribute name
     * @return array{\Sugar\Core\Ast\AttributeNode, int}|null [attribute, index] or null
     */
    public static function findAttributeWithIndex(array $attributes, string $name): ?array
    {
        foreach ($attributes as $index => $attr) {
            if ($attr->name === $name) {
                return [$attr, $index];
            }
        }

        return null;
    }

    /**
     * Returns unique named attributes, excluding spread placeholders.
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Attributes to inspect
     * @return array<int, string> Unique named attribute names in encounter order
     */
    public static function collectNamedAttributeNames(array $attributes): array
    {
        $names = [];

        foreach ($attributes as $attribute) {
            if ($attribute->name === '') {
                continue;
            }

            $names[] = $attribute->name;
        }

        return array_values(array_unique($names));
    }

    /**
     * Converts an attribute value into a PHP expression string.
     *
     * @param \Sugar\Core\Ast\AttributeValue $value Attribute value to convert
     * @param bool $wrapOutputExpressions Whether dynamic expressions are wrapped in parentheses
     * @param string $booleanLiteral Literal expression used for boolean attributes
     */
    public static function attributeValueToPhpExpression(
        AttributeValue $value,
        bool $wrapOutputExpressions = false,
        string $booleanLiteral = 'true',
    ): string {
        if ($value->isOutput()) {
            $output = $value->output;
            if ($output instanceof OutputNode) {
                return $wrapOutputExpressions ? sprintf('(%s)', $output->expression) : $output->expression;
            }
        }

        if ($value->isStatic()) {
            return var_export($value->static, true);
        }

        if ($value->isParts()) {
            $parts = $value->toParts() ?? [];
            if ($parts === []) {
                return "''";
            }

            $expressions = array_map(
                static function (string|OutputNode $part) use ($wrapOutputExpressions): string {
                    if (is_string($part)) {
                        return var_export($part, true);
                    }

                    return $wrapOutputExpressions ? sprintf('(%s)', $part->expression) : $part->expression;
                },
                $parts,
            );

            return implode(' . ', $expressions);
        }

        return $booleanLiteral;
    }

    /**
     * Normalizes compiled PHP snippets to plain expression code.
     *
     * @param string $compiledCode Compiled snippet, e.g. <?= expr ?> or <?php echo expr ?>
     * @return string Extracted expression
     */
    public static function normalizeCompiledPhpExpression(string $compiledCode): string
    {
        return trim(str_replace(['<?=', '?>', '<?php', 'echo'], '', $compiledCode));
    }
}
