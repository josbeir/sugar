<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Trait;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\AttributeValue;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;
use Sugar\Tests\Helper\Builder\DirectiveNodeBuilder;
use Sugar\Tests\Helper\Builder\DocumentNodeBuilder;
use Sugar\Tests\Helper\Builder\ElementNodeBuilder;

/**
 * Trait providing fluent builders for test node creation
 */
trait NodeBuildersTrait
{
    /**
     * Start building a directive node
     */
    protected function directive(string $name): DirectiveNodeBuilder
    {
        return new DirectiveNodeBuilder($name);
    }

    /**
     * Start building an element node
     */
    protected function element(string $tag): ElementNodeBuilder
    {
        return new ElementNodeBuilder($tag);
    }

    /**
     * Start building a document node
     */
    protected function document(): DocumentNodeBuilder
    {
        return new DocumentNodeBuilder();
    }

    /**
     * Create a text node
     */
    protected function text(string $content, int $line = 1, int $column = 0): TextNode
    {
        return new TextNode($content, $line, $column);
    }

    /**
     * Create a raw PHP node
     */
    protected function rawPhp(string $code, int $line = 1, int $column = 0): RawPhpNode
    {
        return new RawPhpNode($code, $line, $column);
    }

    /**
     * Create an output node
     */
    protected function outputNode(
        string $expression,
        bool $escape = true,
        OutputContext $context = OutputContext::HTML,
        int $line = 1,
        int $column = 0,
    ): OutputNode {
        return new OutputNode($expression, $escape, $context, $line, $column);
    }

    /**
     * Create an attribute node
     */
    protected function attribute(string $name, string $value, int $line = 1, int $column = 0): AttributeNode
    {
        return new AttributeNode($name, AttributeValue::static($value), $line, $column);
    }

    /**
     * Create an attribute node with any value (including null or OutputNode)
     */
    protected function attributeNode(
        string $name,
        AttributeValue|OutputNode|string|null $value,
        int $line = 1,
        int $column = 0,
    ): AttributeNode {
        $attributeValue = $value instanceof AttributeValue ? $value : AttributeValue::from($value);

        return new AttributeNode($name, $attributeValue, $line, $column);
    }

    /**
     * Create a fragment node
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes
     * @param array<\Sugar\Ast\Node> $children
     */
    protected function fragment(
        array $attributes = [],
        array $children = [],
        int $line = 1,
        int $column = 0,
        bool $selfClosing = false,
    ): FragmentNode {
        return new FragmentNode($attributes, $children, $line, $column, $selfClosing);
    }

    /**
     * Create a component node
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes
     * @param array<\Sugar\Ast\Node> $children
     */
    protected function component(
        string $name,
        array $attributes = [],
        array $children = [],
        int $line = 1,
        int $column = 0,
    ): ComponentNode {
        return new ComponentNode($name, $attributes, $children, $line, $column);
    }
}
