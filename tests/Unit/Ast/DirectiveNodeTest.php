<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Enum\OutputContext;

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
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $this->assertSame('if', $node->name);
        $this->assertSame('$user->isAdmin', $node->expression);
        $this->assertCount(1, $node->children);
        $this->assertNull($node->elseChildren);
        $this->assertSame(1, $node->line);
        $this->assertSame(1, $node->column);
    }

    public function testDirectiveNodeWithElseChildren(): void
    {
        $children = [new TextNode('Admin Panel', 1, 1)];
        $elseChildren = [new TextNode('Guest Panel', 2, 1)];

        $node = new DirectiveNode(
            name: 'if',
            expression: '$user->isAdmin',
            children: $children,
            elseChildren: $elseChildren,
            line: 1,
            column: 1,
        );

        $this->assertSame('if', $node->name);
        $this->assertCount(1, $node->children);
        $this->assertNotNull($node->elseChildren);
        $this->assertCount(1, $node->elseChildren);
        $this->assertInstanceOf(TextNode::class, $node->elseChildren[0]);
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
            elseChildren: null,
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
            elseChildren: null,
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
            elseChildren: null,
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
            elseChildren: [],
            line: 1,
            column: 1,
        );

        $this->assertCount(0, $node->children);
        $this->assertNotNull($node->elseChildren);
        $this->assertCount(0, $node->elseChildren);
    }

    public function testDirectiveNodePreservesLineColumn(): void
    {
        $node = new DirectiveNode(
            name: 'foreach',
            expression: '$items as $item',
            children: [new TextNode('Item', 42, 15)],
            elseChildren: null,
            line: 42,
            column: 5,
        );

        $this->assertSame(42, $node->line);
        $this->assertSame(5, $node->column);
    }
}
