<?php
declare(strict_types=1);

namespace Sugar\Core\Ast\Helper;

use LogicException;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawBodyNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\RuntimeCallNode;
use Sugar\Core\Ast\TextNode;

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
     * Deep clone a document node and its full subtree.
     */
    public static function cloneDocument(DocumentNode $document): DocumentNode
    {
        $cloned = new DocumentNode(
            children: self::cloneNodes($document->children),
            line: $document->line,
            column: $document->column,
        );
        $cloned->setTemplatePath($document->getTemplatePath());

        return $cloned;
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $nodes
     * @return array<\Sugar\Core\Ast\Node>
     */
    public static function cloneNodes(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $result[] = self::cloneNode($node);
        }

        return $result;
    }

    /**
     * Deep clone any supported AST node.
     */
    public static function cloneNode(Node $node): Node
    {
        $cloned = match (true) {
            $node instanceof DocumentNode => self::cloneDocument($node),
            $node instanceof ElementNode => new ElementNode(
                tag: $node->tag,
                attributes: self::cloneAttributes($node->attributes),
                children: self::cloneNodes($node->children),
                selfClosing: $node->selfClosing,
                line: $node->line,
                column: $node->column,
                dynamicTag: $node->dynamicTag,
            ),
            $node instanceof FragmentNode => new FragmentNode(
                attributes: self::cloneAttributes($node->attributes),
                children: self::cloneNodes($node->children),
                line: $node->line,
                column: $node->column,
                selfClosing: $node->selfClosing,
            ),
            $node instanceof TextNode => new TextNode($node->content, $node->line, $node->column),
            $node instanceof RawPhpNode => new RawPhpNode($node->code, $node->line, $node->column),
            $node instanceof RawBodyNode => new RawBodyNode($node->content, $node->line, $node->column),
            $node instanceof OutputNode => new OutputNode(
                expression: $node->expression,
                escape: $node->escape,
                context: $node->context,
                line: $node->line,
                column: $node->column,
                pipes: $node->pipes,
            ),
            $node instanceof ComponentNode => new ComponentNode(
                name: $node->name,
                attributes: self::cloneAttributes($node->attributes),
                children: self::cloneNodes($node->children),
                line: $node->line,
                column: $node->column,
            ),
            $node instanceof RuntimeCallNode => new RuntimeCallNode(
                callableExpression: $node->callableExpression,
                arguments: $node->arguments,
                line: $node->line,
                column: $node->column,
            ),
            default => throw new LogicException(sprintf('Unsupported AST node type for deep clone: %s', $node::class)),
        };

        $cloned->setTemplatePath($node->getTemplatePath());

        return $cloned;
    }

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

    /**
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes
     * @return array<\Sugar\Core\Ast\AttributeNode>
     */
    public static function cloneAttributes(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $attribute) {
            $result[] = self::cloneAttribute($attribute);
        }

        return $result;
    }

    /**
     * Deep clone an attribute node and value payload.
     */
    public static function cloneAttribute(AttributeNode $attribute): AttributeNode
    {
        $cloned = new AttributeNode(
            name: $attribute->name,
            value: self::cloneAttributeValue($attribute->value),
            line: $attribute->line,
            column: $attribute->column,
        );
        $cloned->setTemplatePath($attribute->getTemplatePath());

        return $cloned;
    }

    /**
     * Deep clone attribute value wrapper.
     */
    public static function cloneAttributeValue(AttributeValue $value): AttributeValue
    {
        if ($value->isBoolean()) {
            return AttributeValue::boolean();
        }

        if ($value->isStatic()) {
            return AttributeValue::static((string)$value->static);
        }

        if ($value->isOutput()) {
            $output = $value->output;
            if (!$output instanceof OutputNode) {
                throw new LogicException('Expected OutputNode when cloning attribute value output.');
            }

            $cloned = self::cloneNode($output);
            if (!$cloned instanceof OutputNode) {
                throw new LogicException('Expected OutputNode when cloning attribute value output.');
            }

            return AttributeValue::output($cloned);
        }

        $parts = $value->toParts() ?? [];
        $clonedParts = [];
        foreach ($parts as $part) {
            if ($part instanceof OutputNode) {
                $cloned = self::cloneNode($part);
                if (!$cloned instanceof OutputNode) {
                    throw new LogicException('Expected OutputNode when cloning attribute value part.');
                }

                $clonedParts[] = $cloned;

                continue;
            }

            $clonedParts[] = $part;
        }

        return AttributeValue::parts($clonedParts);
    }
}
