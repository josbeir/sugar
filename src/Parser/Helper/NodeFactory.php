<?php
declare(strict_types=1);

namespace Sugar\Parser\Helper;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;

/**
 * Factory for creating parser nodes with shared defaults.
 */
final class NodeFactory
{
    /**
     * Create a text node.
     */
    public function text(string $content, int $line, int $column): TextNode
    {
        return new TextNode($content, $line, $column);
    }

    /**
     * Create an output node.
     *
     * @param array<string>|null $pipes
     */
    public function output(
        string $expression,
        bool $escape,
        OutputContext $context,
        int $line,
        int $column,
        ?array $pipes,
    ): OutputNode {
        return new OutputNode(
            expression: $expression,
            escape: $escape,
            context: $context,
            line: $line,
            column: $column,
            pipes: $pipes,
        );
    }

    /**
     * Create a raw PHP node.
     */
    public function rawPhp(string $code, int $line, int $column): RawPhpNode
    {
        return new RawPhpNode($code, $line, $column);
    }

    /**
     * Create an attribute node.
     *
     * @param \Sugar\Ast\OutputNode|array<int, string|\Sugar\Ast\OutputNode>|string|null $value
     */
    public function attribute(string $name, string|OutputNode|array|null $value, int $line, int $column): AttributeNode
    {
        return new AttributeNode($name, $value, $line, $column);
    }

    /**
     * Create an element node.
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes
     */
    public function element(
        string $tag,
        array $attributes,
        bool $selfClosing,
        int $line,
        int $column,
    ): ElementNode {
        return new ElementNode(
            tag: $tag,
            attributes: $attributes,
            children: [],
            selfClosing: $selfClosing,
            line: $line,
            column: $column,
        );
    }

    /**
     * Create a fragment node.
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes
     */
    public function fragment(
        array $attributes,
        bool $selfClosing,
        int $line,
        int $column,
    ): FragmentNode {
        return new FragmentNode(
            attributes: $attributes,
            children: [],
            line: $line,
            column: $column,
            selfClosing: $selfClosing,
        );
    }

    /**
     * Create a component node.
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes
     */
    public function component(
        string $name,
        array $attributes,
        int $line,
        int $column,
    ): ComponentNode {
        return new ComponentNode(
            name: $name,
            attributes: $attributes,
            children: [],
            line: $line,
            column: $column,
        );
    }

    /**
     * Create a closing tag marker.
     */
    public function closingTagMarker(string $tagName): ClosingTagMarker
    {
        return new ClosingTagMarker($tagName);
    }
}
