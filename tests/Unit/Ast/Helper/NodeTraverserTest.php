<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Ast\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Helper\NodeTraverser;
use Sugar\Ast\Node;
use Sugar\Ast\TextNode;

/**
 * Tests for NodeTraverser utility
 */
final class NodeTraverserTest extends TestCase
{
    public function testWalkTransformsNodes(): void
    {
        $nodes = [
            new TextNode('Hello', 1, 1),
            new ElementNode('div', [], [], false, 1, 1),
            new TextNode('World', 1, 1),
        ];

        $result = NodeTraverser::walk($nodes, function (Node $node, callable $recurse) {
            if ($node instanceof TextNode) {
                return new TextNode(strtoupper($node->content), $node->line, $node->column);
            }

            return $recurse($node);
        });

        $this->assertCount(3, $result);
        $this->assertInstanceOf(TextNode::class, $result[0]);
        $this->assertInstanceOf(ElementNode::class, $result[1]);
        $this->assertInstanceOf(TextNode::class, $result[2]);

        $firstNode = $result[0];
        $lastNode = $result[2];

        $this->assertSame('HELLO', $firstNode->content);
        $this->assertSame('WORLD', $lastNode->content);
    }

    public function testWalkCanReturnMultipleNodes(): void
    {
        $nodes = [
            new TextNode('Split', 1, 1),
        ];

        $result = NodeTraverser::walk($nodes, function (Node $node, callable $recurse) {
            if ($node instanceof TextNode) {
                return [
                    new TextNode('First', 1, 1),
                    new TextNode('Second', 1, 1),
                ];
            }

            return $recurse($node);
        });

        $this->assertCount(2, $result);
        $this->assertInstanceOf(TextNode::class, $result[0]);
        $this->assertInstanceOf(TextNode::class, $result[1]);

        $firstNode = $result[0];
        $secondNode = $result[1];

        $this->assertSame('First', $firstNode->content);
        $this->assertSame('Second', $secondNode->content);
    }

    public function testWalkRecursivelyProcessesChildren(): void
    {
        $nodes = [
            new ElementNode('div', [], [
                new TextNode('Child', 1, 1),
            ], false, 1, 1),
        ];

        $result = NodeTraverser::walk($nodes, function (Node $node, callable $recurse) {
            if ($node instanceof TextNode) {
                return new TextNode('Transformed', $node->line, $node->column);
            }

            return $recurse($node);
        });

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);

        $element = $result[0];
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(TextNode::class, $element->children[0]);

        $child = $element->children[0];
        $this->assertSame('Transformed', $child->content);
    }

    public function testWalkRecursiveVisitsAllNodes(): void
    {
        $visited = [];
        $root = new ElementNode('root', [], [
            new TextNode('A', 1, 1),
            new ElementNode('div', [], [
                new TextNode('B', 1, 1),
                new ElementNode('span', [], [
                    new TextNode('C', 1, 1),
                ], false, 1, 1),
            ], false, 1, 1),
        ], false, 1, 1);

        NodeTraverser::walkRecursive($root, function (Node $node) use (&$visited): void {
            if ($node instanceof TextNode) {
                $visited[] = $node->content;
            }
        });

        $this->assertSame(['A', 'B', 'C'], $visited);
    }

    public function testWalkRecursiveVisitsElements(): void
    {
        $visited = [];
        $root = new ElementNode('root', [], [
            new ElementNode('div', [], [
                new ElementNode('span', [], [], false, 1, 1),
            ], false, 1, 1),
        ], false, 1, 1);

        NodeTraverser::walkRecursive($root, function (Node $node) use (&$visited): void {
            if ($node instanceof ElementNode) {
                $visited[] = $node->tag;
            }
        });

        $this->assertSame(['root', 'div', 'span'], $visited);
    }

    public function testFindFirstReturnsFirstMatch(): void
    {
        $root = new ElementNode('root', [], [
            new TextNode('Skip', 1, 1),
            new ElementNode('div', [], [], false, 1, 1),
            new ElementNode('span', [], [], false, 1, 1),
        ], false, 1, 1);

        $result = NodeTraverser::findFirst($root, function (Node $node): bool {
            return $node instanceof ElementNode && $node->tag === 'div';
        });

        $this->assertInstanceOf(ElementNode::class, $result);

        $element = $result;
        $this->assertSame('div', $element->tag);
    }

    public function testFindFirstReturnsNullWhenNoMatch(): void
    {
        $root = new ElementNode('root', [], [
            new TextNode('Only text', 1, 1),
        ], false, 1, 1);

        $result = NodeTraverser::findFirst($root, function (Node $node): bool {
            return $node instanceof ElementNode && $node->tag === 'missing';
        });

        $this->assertNotInstanceOf(Node::class, $result);
    }

    public function testFindFirstSearchesRecursively(): void
    {
        $root = new ElementNode('root', [], [
            new ElementNode('div', [], [
                new ElementNode('span', [], [
                    new ElementNode('target', [], [], false, 1, 1),
                ], false, 1, 1),
            ], false, 1, 1),
        ], false, 1, 1);

        $result = NodeTraverser::findFirst($root, function (Node $node): bool {
            return $node instanceof ElementNode && $node->tag === 'target';
        });

        $this->assertInstanceOf(ElementNode::class, $result);

        $element = $result;
        $this->assertSame('target', $element->tag);
    }

    public function testFindAllReturnsAllMatches(): void
    {
        $root = new ElementNode('root', [], [
            new ElementNode('div', [], [], false, 1, 1),
            new TextNode('Text', 1, 1),
            new ElementNode('span', [], [], false, 1, 1),
            new ElementNode('p', [], [], false, 1, 1),
        ], false, 1, 1);

        $result = NodeTraverser::findAll($root, function (Node $node): bool {
            return $node instanceof ElementNode && $node->tag !== 'root';
        });

        $this->assertCount(3, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertInstanceOf(ElementNode::class, $result[1]);
        $this->assertInstanceOf(ElementNode::class, $result[2]);

        $first = $result[0];
        $second = $result[1];
        $third = $result[2];

        $this->assertSame('div', $first->tag);
        $this->assertSame('span', $second->tag);
        $this->assertSame('p', $third->tag);
    }

    public function testFindAllReturnsEmptyArrayWhenNoMatch(): void
    {
        $root = new ElementNode('root', [], [
            new TextNode('Only text', 1, 1),
        ], false, 1, 1);

        $result = NodeTraverser::findAll($root, function (Node $node): bool {
            return $node instanceof ElementNode && $node->tag === 'missing';
        });

        $this->assertSame([], $result);
    }

    public function testFindAllSearchesRecursively(): void
    {
        $root = new ElementNode('root', [], [
            new ElementNode('div', [], [
                new ElementNode('span', [], [
                    new ElementNode('p', [], [], false, 1, 1),
                ], false, 1, 1),
            ], false, 1, 1),
        ], false, 1, 1);

        $result = NodeTraverser::findAll($root, function (Node $node): bool {
            return $node instanceof ElementNode;
        });

        $this->assertCount(4, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertInstanceOf(ElementNode::class, $result[1]);
        $this->assertInstanceOf(ElementNode::class, $result[2]);
        $this->assertInstanceOf(ElementNode::class, $result[3]);

        $first = $result[0];
        $second = $result[1];
        $third = $result[2];
        $fourth = $result[3];

        $this->assertSame('root', $first->tag);
        $this->assertSame('div', $second->tag);
        $this->assertSame('span', $third->tag);
        $this->assertSame('p', $fourth->tag);
    }

    public function testWalkHandlesEmptyArray(): void
    {
        $result = NodeTraverser::walk([], function (Node $node, callable $recurse) {
            return $recurse($node);
        });

        $this->assertSame([], $result);
    }

    public function testWalkRecursiveHandlesEmptyArray(): void
    {
        $visited = false;
        $root = new ElementNode('root', [], [], false, 1, 1);

        NodeTraverser::walkRecursive($root, function (Node $node) use (&$visited): void {
            if ($node instanceof TextNode) {
                $visited = true;
            }
        });

        $this->assertFalse($visited);
    }

    public function testFindFirstHandlesEmptyArray(): void
    {
        $root = new ElementNode('root', [], [], false, 1, 1);

        $result = NodeTraverser::findFirst($root, function (Node $node): bool {
            return $node instanceof TextNode;
        });

        $this->assertNotInstanceOf(Node::class, $result);
    }

    public function testFindAllHandlesEmptyArray(): void
    {
        $root = new ElementNode('root', [], [], false, 1, 1);

        $result = NodeTraverser::findAll($root, function (Node $node): bool {
            return $node instanceof TextNode;
        });

        $this->assertSame([], $result);
    }
}
