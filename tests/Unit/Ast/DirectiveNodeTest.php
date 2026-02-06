<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;

/**
 * Test DirectiveNode AST class
 */
final class DirectiveNodeTest extends TestCase
{
    public function testDirectiveNodeCreation(): void
    {
        $children = [new TextNode('Hello', 1, 1)];

        $node = new DirectiveNode(
            name: 'if',
            expression: '$user->isAdmin',
            children: $children,
            line: 1,
            column: 1,
        );

        $this->assertSame('if', $node->name);
        $this->assertSame('$user->isAdmin', $node->expression);
        $this->assertCount(1, $node->children);
        $this->assertSame(1, $node->line);
        $this->assertSame(1, $node->column);
    }

    public function testDirectiveNodeWithPairedSibling(): void
    {
        $ifChildren = [new TextNode('Admin Panel', 1, 1)];
        $elseChildren = [new TextNode('Guest Panel', 2, 1)];

        $ifNode = new DirectiveNode(
            name: 'if',
            expression: '$user->isAdmin',
            children: $ifChildren,
            line: 1,
            column: 1,
        );

        $elseNode = new DirectiveNode(
            name: 'else',
            expression: '',
            children: $elseChildren,
            line: 2,
            column: 1,
        );

        $ifNode->setPairedSibling($elseNode);

        $this->assertSame('if', $ifNode->name);
        $this->assertCount(1, $ifNode->children);
        $this->assertInstanceOf(DirectiveNode::class, $ifNode->getPairedSibling());
        $this->assertSame($elseNode, $ifNode->getPairedSibling());
        $this->assertInstanceOf(TextNode::class, $elseNode->children[0]);
    }

    public function testDirectiveNodeWithForeach(): void
    {
        $children = [
            new OutputNode('$user->name', true, OutputContext::HTML, 1, 1),
        ];

        $node = new DirectiveNode(
            name: 'foreach',
            expression: '$users as $user',
            children: $children,
            line: 1,
            column: 1,
        );

        $this->assertSame('foreach', $node->name);
        $this->assertSame('$users as $user', $node->expression);
        $this->assertCount(1, $node->children);
        $this->assertInstanceOf(OutputNode::class, $node->children[0]);
    }

    public function testDirectiveNodeWithComplexChildren(): void
    {
        $children = [
            new TextNode('<div>', 1, 1),
            new OutputNode('$content', true, OutputContext::HTML, 1, 6),
            new TextNode('</div>', 1, 20),
        ];

        $node = new DirectiveNode(
            name: 'if',
            expression: '$showContent',
            children: $children,
            line: 1,
            column: 1,
        );

        $this->assertCount(3, $node->children);
        $this->assertInstanceOf(TextNode::class, $node->children[0]);
        $this->assertInstanceOf(OutputNode::class, $node->children[1]);
        $this->assertInstanceOf(TextNode::class, $node->children[2]);
    }

    public function testDirectiveNodeExtendsNode(): void
    {
        $node = new DirectiveNode(
            name: 'if',
            expression: '$test',
            children: [],
            line: 5,
            column: 10,
        );

        $this->assertInstanceOf(Node::class, $node);
    }

    public function testDirectiveNodeWithEmptyChildren(): void
    {
        $node = new DirectiveNode(
            name: 'if',
            expression: '$isEmpty',
            children: [],
            line: 1,
            column: 1,
        );

        $this->assertCount(0, $node->children);
    }

    public function testDirectiveNodePreservesLineColumn(): void
    {
        $node = new DirectiveNode(
            name: 'foreach',
            expression: '$items as $item',
            children: [new TextNode('Item', 42, 15)],
            line: 42,
            column: 5,
        );

        $this->assertSame(42, $node->line);
        $this->assertSame(5, $node->column);
    }

    public function testConsumedByPairingFlag(): void
    {
        $node = new DirectiveNode(
            name: 'empty',
            expression: '',
            children: [],
            line: 1,
            column: 1,
        );

        $this->assertFalse($node->isConsumedByPairing());

        $node->markConsumedByPairing();

        $this->assertTrue($node->isConsumedByPairing());
    }

    public function testGetNextSibling(): void
    {
        $child1 = new TextNode('First', 1, 1);
        $child2 = new TextNode('Second', 1, 10);
        $child3 = new TextNode('Third', 1, 20);

        $node = new DirectiveNode(
            name: 'if',
            expression: '$test',
            children: [$child1, $child2, $child3],
            line: 1,
            column: 1,
        );

        $this->assertSame($child2, $node->getNextSibling($child1));
        $this->assertSame($child3, $node->getNextSibling($child2));
        $this->assertNotInstanceOf(Node::class, $node->getNextSibling($child3));
    }

    public function testGetNextSiblingReturnsNullForNonChild(): void
    {
        $child = new TextNode('Child', 1, 1);
        $nonChild = new TextNode('Not a child', 2, 1);

        $node = new DirectiveNode(
            name: 'if',
            expression: '$test',
            children: [$child],
            line: 1,
            column: 1,
        );

        $this->assertNotInstanceOf(Node::class, $node->getNextSibling($nonChild));
    }

    public function testGetPreviousSibling(): void
    {
        $child1 = new TextNode('First', 1, 1);
        $child2 = new TextNode('Second', 1, 10);
        $child3 = new TextNode('Third', 1, 20);

        $node = new DirectiveNode(
            name: 'if',
            expression: '$test',
            children: [$child1, $child2, $child3],
            line: 1,
            column: 1,
        );

        $this->assertNotInstanceOf(Node::class, $node->getPreviousSibling($child1));
        $this->assertSame($child1, $node->getPreviousSibling($child2));
        $this->assertSame($child2, $node->getPreviousSibling($child3));
    }

    public function testGetPreviousSiblingReturnsNullForNonChild(): void
    {
        $child = new TextNode('Child', 1, 1);
        $nonChild = new TextNode('Not a child', 2, 1);

        $node = new DirectiveNode(
            name: 'if',
            expression: '$test',
            children: [$child],
            line: 1,
            column: 1,
        );

        $this->assertNotInstanceOf(Node::class, $node->getPreviousSibling($nonChild));
    }

    public function testFindNextSibling(): void
    {
        $text1 = new TextNode('Text 1', 1, 1);
        $output = new OutputNode('$var', true, OutputContext::HTML, 1, 10);
        $text2 = new TextNode('Text 2', 1, 20);
        $text3 = new TextNode('Text 3', 1, 30);

        $node = new DirectiveNode(
            name: 'if',
            expression: '$test',
            children: [$text1, $output, $text2, $text3],
            line: 1,
            column: 1,
        );

        // Find next TextNode after first text
        $found = $node->findNextSibling($text1, fn($n): bool => $n instanceof TextNode);
        $this->assertSame($text2, $found);

        // Find next OutputNode after first text
        $found = $node->findNextSibling($text1, fn($n): bool => $n instanceof OutputNode);
        $this->assertSame($output, $found);

        // Find next sibling that doesn't exist
        $found = $node->findNextSibling($text3, fn($n): bool => $n instanceof OutputNode);
        $this->assertNotInstanceOf(Node::class, $found);
    }

    public function testFindNextSiblingReturnsNullForNonChild(): void
    {
        $child = new TextNode('Child', 1, 1);
        $nonChild = new TextNode('Not a child', 2, 1);

        $node = new DirectiveNode(
            name: 'if',
            expression: '$test',
            children: [$child],
            line: 1,
            column: 1,
        );

        $found = $node->findNextSibling($nonChild, fn($n): true => true);
        $this->assertNotInstanceOf(Node::class, $found);
    }
}
