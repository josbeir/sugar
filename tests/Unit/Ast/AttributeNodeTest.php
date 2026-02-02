<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Enum\OutputContext;

/**
 * Test AttributeNode AST class
 */
final class AttributeNodeTest extends TestCase
{
    public function testAttributeNodeWithStaticValue(): void
    {
        $node = new AttributeNode(
            name: 'class',
            value: 'btn btn-primary',
            line: 1,
            column: 5,
        );

        $this->assertSame('class', $node->name);
        $this->assertSame('btn btn-primary', $node->value);
        $this->assertSame(1, $node->line);
        $this->assertSame(5, $node->column);
    }

    public function testAttributeNodeWithDynamicValue(): void
    {
        $outputNode = new OutputNode('$userId', true, OutputContext::HTML_ATTRIBUTE, 1, 10);

        $node = new AttributeNode(
            name: 'data-user-id',
            value: $outputNode,
            line: 1,
            column: 5,
        );

        $this->assertSame('data-user-id', $node->name);
        $this->assertInstanceOf(OutputNode::class, $node->value);
        $this->assertSame($outputNode, $node->value);
    }

    public function testAttributeNodeWithNullValue(): void
    {
        $node = new AttributeNode(
            name: 'disabled',
            value: null,
            line: 1,
            column: 5,
        );

        $this->assertSame('disabled', $node->name);
        $this->assertNull($node->value);
    }

    public function testAttributeNodeExtendsNode(): void
    {
        $node = new AttributeNode(
            name: 'id',
            value: 'test-id',
            line: 1,
            column: 1,
        );

        $this->assertInstanceOf(Node::class, $node);
    }

    public function testAttributeNodeWithBooleanAttribute(): void
    {
        $node = new AttributeNode(
            name: 'required',
            value: null,
            line: 2,
            column: 15,
        );

        $this->assertSame('required', $node->name);
        $this->assertNull($node->value);
    }

    public function testAttributeNodePreservesLineColumn(): void
    {
        $node = new AttributeNode(
            name: 'href',
            value: '/path/to/page',
            line: 42,
            column: 20,
        );

        $this->assertSame(42, $node->line);
        $this->assertSame(20, $node->column);
    }
}
