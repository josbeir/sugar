<?php
declare(strict_types=1);

namespace Sugar\Ast\Helper;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;

/**
 * Helper for AST attribute operations during compilation
 *
 * Provides utilities for finding, filtering, and manipulating attributes
 * on AST nodes during the compilation process.
 *
 * Note: This is for compile-time AST manipulation. For runtime HTML attribute
 * helpers, see \Sugar\Runtime\AttributeHelper.
 */
final class AttributeHelper
{
    /**
     * Find attribute by exact name match
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes Attributes to search
     * @param string $name Attribute name to find
     * @return \Sugar\Ast\AttributeNode|null Found attribute or null
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
     * @param array<\Sugar\Ast\AttributeNode> $attributes Attributes to search
     * @param string $prefix Prefix to match (e.g., 's:', 's-bind:')
     * @return array<\Sugar\Ast\AttributeNode> Matching attributes
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
     * @param \Sugar\Ast\ElementNode|\Sugar\Ast\FragmentNode $node Node to check
     * @param string $name Attribute name
     */
    public static function hasAttribute(ElementNode|FragmentNode $node, string $name): bool
    {
        return self::findAttribute($node->attributes, $name) instanceof AttributeNode;
    }

    /**
     * Check if node has any attribute matching prefix
     *
     * @param \Sugar\Ast\ElementNode|\Sugar\Ast\FragmentNode $node Node to check
     * @param string $prefix Prefix to match
     */
    public static function hasAttributeWithPrefix(ElementNode|FragmentNode $node, string $prefix): bool
    {
        return self::findAttributesByPrefix($node->attributes, $prefix) !== [];
    }

    /**
     * Get attribute value or return default
     *
     * @param \Sugar\Ast\ElementNode|\Sugar\Ast\FragmentNode $node Node to get attribute from
     * @param string $name Attribute name
     * @param mixed $default Default value if attribute not found
     * @return mixed Attribute value or default
     */
    public static function getAttributeValue(ElementNode|FragmentNode $node, string $name, mixed $default = null): mixed
    {
        $attr = self::findAttribute($node->attributes, $name);

        return $attr instanceof AttributeNode ? $attr->value : $default;
    }

    /**
     * Remove attribute by name (returns new array)
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes Attributes array
     * @param string $name Attribute name to remove
     * @return array<\Sugar\Ast\AttributeNode> New array without the attribute
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
     * @param array<\Sugar\Ast\AttributeNode> $attributes Attributes array
     * @param callable(\Sugar\Ast\AttributeNode): bool $predicate Filter function
     * @return array<\Sugar\Ast\AttributeNode> New filtered array
     */
    public static function filterAttributes(array $attributes, callable $predicate): array
    {
        return array_values(array_filter($attributes, $predicate));
    }

    /**
     * Find attribute index by name
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes Attributes to search
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
     * @param array<\Sugar\Ast\AttributeNode> $attributes Attributes to search
     * @param string $name Attribute name
     * @return array{\Sugar\Ast\AttributeNode, int}|null [attribute, index] or null
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
}
