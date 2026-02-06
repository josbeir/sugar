<?php
declare(strict_types=1);

namespace Sugar\Tests;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;
use Sugar\Tests\Builder\DirectiveNodeBuilder;
use Sugar\Tests\Builder\DocumentNodeBuilder;
use Sugar\Tests\Builder\ElementNodeBuilder;

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
        return new AttributeNode($name, $value, $line, $column);
    }
}
