<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Ast;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Escape\Enum\OutputContext;

/**
 * Test ElementNode AST class
 */
final class ElementNodeTest extends TestCase
{
    public function testElementNodeCreation(): void
    {
        $attributes = [
            new AttributeNode('class', AttributeValue::static('container'), 1, 5),
        ];
        $children = [
            new TextNode('Hello World', 1, 20),
        ];

        $node = new ElementNode(
            tag: 'div',
            attributes: $attributes,
            children: $children,
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $this->assertSame('div', $node->tag);
        $this->assertCount(1, $node->attributes);
        $this->assertCount(1, $node->children);
        $this->assertFalse($node->selfClosing);
        $this->assertSame(1, $node->line);
        $this->assertSame(1, $node->column);
    }

    public function testElementNodeWithMultipleAttributes(): void
    {
        $attributes = [
            new AttributeNode('class', AttributeValue::static('btn'), 1, 5),
            new AttributeNode('id', AttributeValue::static('submit-btn'), 1, 15),
            new AttributeNode('type', AttributeValue::static('submit'), 1, 30),
        ];

        $node = new ElementNode(
            tag: 'button',
            attributes: $attributes,
            children: [new TextNode('Submit', 1, 45)],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $this->assertCount(3, $node->attributes);
        $this->assertInstanceOf(AttributeNode::class, $node->attributes[0]);
        $this->assertSame('class', $node->attributes[0]->name);
        $this->assertSame('id', $node->attributes[1]->name);
        $this->assertSame('type', $node->attributes[2]->name);
    }

    public function testElementNodeWithDynamicAttributes(): void
    {
        $outputNode = new OutputNode('$url', true, OutputContext::HTML_ATTRIBUTE, 1, 15);
        $attributes = [
            new AttributeNode('href', AttributeValue::output($outputNode), 1, 10),
        ];

        $node = new ElementNode(
            tag: 'a',
            attributes: $attributes,
            children: [new TextNode('Link', 1, 25)],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $this->assertTrue($node->attributes[0]->value->isOutput());
        $this->assertInstanceOf(OutputNode::class, $node->attributes[0]->value->output);
    }

    public function testElementNodeSelfClosing(): void
    {
        $attributes = [
            new AttributeNode('src', AttributeValue::static('image.jpg'), 1, 5),
            new AttributeNode('alt', AttributeValue::static('Description'), 1, 20),
        ];

        $node = new ElementNode(
            tag: 'img',
            attributes: $attributes,
            children: [],
            selfClosing: true,
            line: 1,
            column: 1,
        );

        $this->assertSame('img', $node->tag);
        $this->assertTrue($node->selfClosing);
        $this->assertCount(0, $node->children);
    }

    public function testElementNodeWithNestedElements(): void
    {
        $innerElement = new ElementNode(
            tag: 'span',
            attributes: [],
            children: [new TextNode('Inner', 2, 10)],
            selfClosing: false,
            line: 2,
            column: 5,
        );

        $node = new ElementNode(
            tag: 'div',
            attributes: [],
            children: [$innerElement],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $this->assertCount(1, $node->children);
        $this->assertInstanceOf(ElementNode::class, $node->children[0]);
        $this->assertSame('span', $node->children[0]->tag);
    }

    public function testElementNodeWithMixedChildren(): void
    {
        $children = [
            new TextNode('Text before', 1, 10),
            new OutputNode('$variable', true, OutputContext::HTML, 1, 25),
            new TextNode('Text after', 1, 40),
        ];

        $node = new ElementNode(
            tag: 'p',
            attributes: [],
            children: $children,
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $this->assertCount(3, $node->children);
        $this->assertInstanceOf(TextNode::class, $node->children[0]);
        $this->assertInstanceOf(OutputNode::class, $node->children[1]);
        $this->assertInstanceOf(TextNode::class, $node->children[2]);
    }

    public function testElementNodeWithNoAttributes(): void
    {
        $node = new ElementNode(
            tag: 'div',
            attributes: [],
            children: [new TextNode('Content', 1, 5)],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $this->assertCount(0, $node->attributes);
    }

    public function testElementNodeWithNoChildren(): void
    {
        $node = new ElementNode(
            tag: 'br',
            attributes: [],
            children: [],
            selfClosing: true,
            line: 1,
            column: 1,
        );

        $this->assertCount(0, $node->children);
    }

    public function testElementNodeExtendsNode(): void
    {
        $node = new ElementNode(
            tag: 'div',
            attributes: [],
            children: [],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $this->assertInstanceOf(Node::class, $node);
    }

    public function testElementNodePreservesLineColumn(): void
    {
        $node = new ElementNode(
            tag: 'section',
            attributes: [],
            children: [],
            selfClosing: false,
            line: 42,
            column: 15,
        );

        $this->assertSame(42, $node->line);
        $this->assertSame(15, $node->column);
    }
}
