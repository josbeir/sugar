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
        $this->assertNotNull($ifNode->getPairedSibling());
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
}
