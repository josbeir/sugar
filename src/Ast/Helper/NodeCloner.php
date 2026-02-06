<?php
declare(strict_types=1);

namespace Sugar\Ast\Helper;

use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;

/**
 * Safe immutable cloning utilities for AST nodes
 *
 * Provides helpers for creating modified copies of nodes while preserving
 * immutability. Useful when you need to update a node's properties without
 * mutating the original.
 */
final class NodeCloner
{
    /**
     * Clone ElementNode with modified attributes
     *
     * @param \Sugar\Ast\ElementNode $node Original node
     * @param array<\Sugar\Ast\AttributeNode> $newAttributes New attributes array
     * @return \Sugar\Ast\ElementNode New node with updated attributes
     */
    public static function withAttributes(ElementNode $node, array $newAttributes): ElementNode
    {
        return new ElementNode(
            tag: $node->tag,
            attributes: $newAttributes,
            children: $node->children,
            selfClosing: $node->selfClosing,
            line: $node->line,
            column: $node->column,
            dynamicTag: $node->dynamicTag,
        );
    }

    /**
     * Clone ElementNode with modified children
     *
     * @param \Sugar\Ast\ElementNode $node Original node
     * @param array<\Sugar\Ast\Node> $newChildren New children array
     * @return \Sugar\Ast\ElementNode New node with updated children
     */
    public static function withChildren(ElementNode $node, array $newChildren): ElementNode
    {
        return new ElementNode(
            tag: $node->tag,
            attributes: $node->attributes,
            children: $newChildren,
            selfClosing: $node->selfClosing,
            line: $node->line,
            column: $node->column,
            dynamicTag: $node->dynamicTag,
        );
    }

    /**
     * Clone ElementNode with modified attributes and children
     *
     * @param \Sugar\Ast\ElementNode $node Original node
     * @param array<\Sugar\Ast\AttributeNode> $newAttributes New attributes array
     * @param array<\Sugar\Ast\Node> $newChildren New children array
     * @return \Sugar\Ast\ElementNode New node with updates
     */
    public static function withAttributesAndChildren(
        ElementNode $node,
        array $newAttributes,
        array $newChildren,
    ): ElementNode {
        return new ElementNode(
            tag: $node->tag,
            attributes: $newAttributes,
            children: $newChildren,
            selfClosing: $node->selfClosing,
            line: $node->line,
            column: $node->column,
            dynamicTag: $node->dynamicTag,
        );
    }

    /**
     * Clone FragmentNode with modified children
     *
     * @param \Sugar\Ast\FragmentNode $node Original node
     * @param array<\Sugar\Ast\Node> $newChildren New children array
     * @return \Sugar\Ast\FragmentNode New node with updated children
     */
    public static function fragmentWithChildren(FragmentNode $node, array $newChildren): FragmentNode
    {
        return new FragmentNode(
            attributes: $node->attributes,
            children: $newChildren,
            line: $node->line,
            column: $node->column,
        );
    }

    /**
     * Clone FragmentNode with modified attributes
     *
     * @param \Sugar\Ast\FragmentNode $node Original node
     * @param array<\Sugar\Ast\AttributeNode> $newAttributes New attributes array
     * @return \Sugar\Ast\FragmentNode New node with updated attributes
     */
    public static function fragmentWithAttributes(FragmentNode $node, array $newAttributes): FragmentNode
    {
        return new FragmentNode(
            attributes: $newAttributes,
            children: $node->children,
            line: $node->line,
            column: $node->column,
        );
    }
}
