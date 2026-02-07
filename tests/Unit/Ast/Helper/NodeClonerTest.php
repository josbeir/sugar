<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Helper\NodeCloner;
use Sugar\Ast\TextNode;

final class NodeClonerTest extends TestCase
{
    public function testWithChildren(): void
    {
        $node = new ElementNode(
            'div',
            [new AttributeNode('id', 'main', 1, 1)],
            [new TextNode('old', 1, 1)],
            false,
            1,
            1,
        );

        $newChildren = [new TextNode('new', 1, 1)];
        $result = NodeCloner::withChildren($node, $newChildren);

        $this->assertNotSame($node, $result);
        $this->assertSame('div', $result->tag);
        $this->assertSame($node->attributes, $result->attributes);
        $this->assertSame($newChildren, $result->children);
    }

    public function testWithAttributesAndChildren(): void
    {
        $node = new ElementNode(
            'div',
            [new AttributeNode('id', 'old', 1, 1)],
            [new TextNode('old', 1, 1)],
            false,
            1,
            1,
        );

        $newAttributes = [new AttributeNode('id', 'new', 1, 1)];
        $newChildren = [new TextNode('new', 1, 1)];
        $result = NodeCloner::withAttributesAndChildren($node, $newAttributes, $newChildren);

        $this->assertNotSame($node, $result);
        $this->assertSame('div', $result->tag);
        $this->assertSame($newAttributes, $result->attributes);
        $this->assertSame($newChildren, $result->children);
        $this->assertSame($node->selfClosing, $result->selfClosing);
        $this->assertSame($node->line, $result->line);
        $this->assertSame($node->column, $result->column);
    }

    public function testFragmentWithChildren(): void
    {
        $node = new FragmentNode(
            [new AttributeNode('s:if', '$show', 1, 1)],
            [new TextNode('old', 1, 1)],
            1,
            1,
        );

        $newChildren = [new TextNode('new', 1, 1)];
        $result = NodeCloner::fragmentWithChildren($node, $newChildren);

        $this->assertNotSame($node, $result);
        $this->assertSame($node->attributes, $result->attributes);
        $this->assertSame($newChildren, $result->children);
        $this->assertSame($node->line, $result->line);
        $this->assertSame($node->column, $result->column);
    }

    public function testFragmentWithAttributes(): void
    {
        $node = new FragmentNode(
            [new AttributeNode('s:if', 'old', 1, 1)],
            [new TextNode('content', 1, 1)],
            1,
            1,
        );

        $newAttributes = [new AttributeNode('s:if', 'new', 1, 1)];
        $result = NodeCloner::fragmentWithAttributes($node, $newAttributes);

        $this->assertNotSame($node, $result);
        $this->assertSame($newAttributes, $result->attributes);
        $this->assertSame($node->children, $result->children);
        $this->assertSame($node->line, $result->line);
        $this->assertSame($node->column, $result->column);
    }

    public function testImmutabilityWithChildren(): void
    {
        $original = new ElementNode(
            'span',
            [new AttributeNode('class', 'original', 1, 1)],
            [new TextNode('text', 1, 1)],
            false,
            5,
            10,
        );

        $modified = NodeCloner::withChildren($original, []);

        // Original unchanged
        $this->assertCount(1, $original->children);

        // Modified is different
        $this->assertCount(0, $modified->children);
        $this->assertSame($original->tag, $modified->tag);
        $this->assertSame($original->attributes, $modified->attributes);
    }
}
