<?php
declare(strict_types=1);

namespace Sugar\Core\Ast\Helper;

use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;

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
     * Clone ElementNode with modified children
     *
     * @param \Sugar\Core\Ast\ElementNode $node Original node
     * @param array<\Sugar\Core\Ast\Node> $newChildren New children array
     * @return \Sugar\Core\Ast\ElementNode New node with updated children
     */
    public static function withChildren(ElementNode $node, array $newChildren): ElementNode
    {
        $cloned = new ElementNode(
            tag: $node->tag,
            attributes: $node->attributes,
            children: $newChildren,
            selfClosing: $node->selfClosing,
            line: $node->line,
            column: $node->column,
            dynamicTag: $node->dynamicTag,
        );

        $cloned->inheritTemplatePathFrom($node);

        return $cloned;
    }

    /**
     * Clone ElementNode with modified attributes and children
     *
     * @param \Sugar\Core\Ast\ElementNode $node Original node
     * @param array<\Sugar\Core\Ast\AttributeNode> $newAttributes New attributes array
     * @param array<\Sugar\Core\Ast\Node> $newChildren New children array
     * @return \Sugar\Core\Ast\ElementNode New node with updates
     */
    public static function withAttributesAndChildren(
        ElementNode $node,
        array $newAttributes,
        array $newChildren,
    ): ElementNode {
        $cloned = new ElementNode(
            tag: $node->tag,
            attributes: $newAttributes,
            children: $newChildren,
            selfClosing: $node->selfClosing,
            line: $node->line,
            column: $node->column,
            dynamicTag: $node->dynamicTag,
        );

        $cloned->inheritTemplatePathFrom($node);

        return $cloned;
    }

    /**
     * Clone FragmentNode with modified children
     *
     * @param \Sugar\Core\Ast\FragmentNode $node Original node
     * @param array<\Sugar\Core\Ast\Node> $newChildren New children array
     * @return \Sugar\Core\Ast\FragmentNode New node with updated children
     */
    public static function fragmentWithChildren(FragmentNode $node, array $newChildren): FragmentNode
    {
        $cloned = new FragmentNode(
            attributes: $node->attributes,
            children: $newChildren,
            line: $node->line,
            column: $node->column,
            selfClosing: $node->selfClosing,
        );

        $cloned->inheritTemplatePathFrom($node);

        return $cloned;
    }

    /**
     * Clone FragmentNode with modified attributes
     *
     * @param \Sugar\Core\Ast\FragmentNode $node Original node
     * @param array<\Sugar\Core\Ast\AttributeNode> $newAttributes New attributes array
     * @return \Sugar\Core\Ast\FragmentNode New node with updated attributes
     */
    public static function fragmentWithAttributes(FragmentNode $node, array $newAttributes): FragmentNode
    {
        $cloned = new FragmentNode(
            attributes: $newAttributes,
            children: $node->children,
            line: $node->line,
            column: $node->column,
            selfClosing: $node->selfClosing,
        );

        $cloned->inheritTemplatePathFrom($node);

        return $cloned;
    }
}
