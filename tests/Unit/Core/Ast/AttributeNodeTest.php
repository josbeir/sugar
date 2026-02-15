<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Enum\OutputContext;

/**
 * Test AttributeNode AST class
 */
final class AttributeNodeTest extends TestCase
{
    public function testAttributeNodeWithStaticValue(): void
    {
        $node = new AttributeNode(
            name: 'class',
            value: AttributeValue::static('btn btn-primary'),
            line: 1,
            column: 5,
        );

        $this->assertSame('class', $node->name);
        $this->assertTrue($node->value->isStatic());
        $this->assertSame('btn btn-primary', $node->value->static);
        $this->assertSame(1, $node->line);
        $this->assertSame(5, $node->column);
    }

    public function testAttributeNodeWithDynamicValue(): void
    {
        $outputNode = new OutputNode('$userId', true, OutputContext::HTML_ATTRIBUTE, 1, 10);

        $node = new AttributeNode(
            name: 'data-user-id',
            value: AttributeValue::output($outputNode),
            line: 1,
            column: 5,
        );

        $this->assertSame('data-user-id', $node->name);
        $this->assertTrue($node->value->isOutput());
        $this->assertSame($outputNode, $node->value->output);
    }

    public function testAttributeNodeWithNullValue(): void
    {
        $node = new AttributeNode(
            name: 'disabled',
            value: AttributeValue::boolean(),
            line: 1,
            column: 5,
        );

        $this->assertSame('disabled', $node->name);
        $this->assertTrue($node->value->isBoolean());
    }

    public function testAttributeNodeExtendsNode(): void
    {
        $node = new AttributeNode(
            name: 'id',
            value: AttributeValue::static('test-id'),
            line: 1,
            column: 1,
        );

        $this->assertInstanceOf(Node::class, $node);
    }

    public function testAttributeNodeWithBooleanAttribute(): void
    {
        $node = new AttributeNode(
            name: 'required',
            value: AttributeValue::boolean(),
            line: 2,
            column: 15,
        );

        $this->assertSame('required', $node->name);
        $this->assertTrue($node->value->isBoolean());
    }

    public function testAttributeNodePreservesLineColumn(): void
    {
        $node = new AttributeNode(
            name: 'href',
            value: AttributeValue::static('/path/to/page'),
            line: 42,
            column: 20,
        );

        $this->assertSame(42, $node->line);
        $this->assertSame(20, $node->column);
    }
}
