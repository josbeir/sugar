<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Ast;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\AttributeValue;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\Node;
use Sugar\Ast\TextNode;

final class ComponentNodeTest extends TestCase
{
    public function testComponentNodeStoresName(): void
    {
        $node = new ComponentNode(name: 'button');

        $this->assertSame('button', $node->name);
    }

    public function testComponentNodeStoresAttributes(): void
    {
        $attributes = [
            new AttributeNode('type', AttributeValue::static('primary'), 1, 0),
            new AttributeNode('class', AttributeValue::static('btn-large'), 1, 15),
        ];

        $node = new ComponentNode(name: 'button', attributes: $attributes);

        $this->assertSame($attributes, $node->attributes);
    }

    public function testComponentNodeStoresChildren(): void
    {
        $children = [
            new TextNode('Save', 1, 0),
        ];

        $node = new ComponentNode(name: 'button', children: $children);

        $this->assertSame($children, $node->children);
    }

    public function testComponentNodeStoresLineAndColumn(): void
    {
        $node = new ComponentNode(name: 'button', line: 10, column: 5);

        $this->assertSame(10, $node->line);
        $this->assertSame(5, $node->column);
    }

    public function testComponentNodeDefaultsToEmptyArrays(): void
    {
        $node = new ComponentNode(name: 'button');

        $this->assertSame([], $node->attributes);
        $this->assertSame([], $node->children);
    }

    public function testComponentNodeImplementsSiblingNavigation(): void
    {
        $child1 = new TextNode('First', 1, 0);
        $child2 = new TextNode('Second', 1, 10);
        $child3 = new TextNode('Third', 1, 20);

        $node = new ComponentNode(
            name: 'button',
            children: [$child1, $child2, $child3],
        );

        $this->assertSame($child2, $node->getNextSibling($child1));
        $this->assertSame($child3, $node->getNextSibling($child2));
        $this->assertNotInstanceOf(Node::class, $node->getNextSibling($child3));

        $this->assertNotInstanceOf(Node::class, $node->getPreviousSibling($child1));
        $this->assertSame($child1, $node->getPreviousSibling($child2));
        $this->assertSame($child2, $node->getPreviousSibling($child3));
    }
}
