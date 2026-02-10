<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Ast\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Helper\NodeTraverser;
use Sugar\Ast\Node;
use Sugar\Ast\TextNode;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

/**
 * Tests for NodeTraverser utility
 */
final class NodeTraverserTest extends TestCase
{
    use NodeBuildersTrait;

    public function testWalkTransformsNodes(): void
    {
        $nodes = [
            $this->text('Hello', 1, 1),
            $this->element('div')->build(),
            $this->text('World', 1, 1),
        ];

        /**
         * @return \Sugar\Ast\Node
         */
        $visitor = function (Node $node, callable $recurse): Node {
            /** @var callable(\Sugar\Ast\Node): \Sugar\Ast\Node $recurse */
            if ($node instanceof TextNode) {
                return new TextNode(strtoupper($node->content), $node->line, $node->column);
            }

            return $recurse($node);
        };

        $result = NodeTraverser::walk($nodes, $visitor);

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
            $this->text('Split', 1, 1),
        ];

        /**
         * @return \Sugar\Ast\Node|array<\Sugar\Ast\Node>
         */
        $visitor = function (Node $node, callable $recurse): Node|array {
            /** @var callable(\Sugar\Ast\Node): \Sugar\Ast\Node $recurse */
            if ($node instanceof TextNode) {
                return [
                    new TextNode('First', 1, 1),
                    new TextNode('Second', 1, 1),
                ];
            }

            return $recurse($node);
        };

        $result = NodeTraverser::walk($nodes, $visitor);

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
            $this->element('div')
                ->withChild($this->text('Child', 1, 1))
                ->build(),
        ];

        /**
         * @return \Sugar\Ast\Node
         */
        $visitor = function (Node $node, callable $recurse): Node {
            /** @var callable(\Sugar\Ast\Node): \Sugar\Ast\Node $recurse */
            if ($node instanceof TextNode) {
                return new TextNode('Transformed', $node->line, $node->column);
            }

            return $recurse($node);
        };

        $result = NodeTraverser::walk($nodes, $visitor);

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
        $root = $this->element('root')
            ->withChildren([
                $this->text('A', 1, 1),
                $this->element('div')
                    ->withChildren([
                        $this->text('B', 1, 1),
                        $this->element('span')
                            ->withChild($this->text('C', 1, 1))
                            ->build(),
                    ])
                    ->build(),
            ])
            ->build();

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
        $root = $this->element('root')
            ->withChild(
                $this->element('div')
                    ->withChild($this->element('span')->build())
                    ->build(),
            )
            ->build();

        NodeTraverser::walkRecursive($root, function (Node $node) use (&$visited): void {
            if ($node instanceof ElementNode) {
                $visited[] = $node->tag;
            }
        });

        $this->assertSame(['root', 'div', 'span'], $visited);
    }

    public function testFindFirstReturnsFirstMatch(): void
    {
        $root = $this->element('root')
            ->withChildren([
                $this->text('Skip', 1, 1),
                $this->element('div')->build(),
                $this->element('span')->build(),
            ])
            ->build();

        $result = NodeTraverser::findFirst($root, function (Node $node): bool {
            return $node instanceof ElementNode && $node->tag === 'div';
        });

        $this->assertInstanceOf(ElementNode::class, $result);

        $element = $result;
        $this->assertSame('div', $element->tag);
    }

    public function testFindFirstReturnsNullWhenNoMatch(): void
    {
        $root = $this->element('root')
            ->withChild($this->text('Only text', 1, 1))
            ->build();

        $result = NodeTraverser::findFirst($root, function (Node $node): bool {
            return $node instanceof ElementNode && $node->tag === 'missing';
        });

        $this->assertNotInstanceOf(Node::class, $result);
    }

    public function testFindFirstSearchesRecursively(): void
    {
        $root = $this->element('root')
            ->withChild(
                $this->element('div')
                    ->withChild(
                        $this->element('span')
                            ->withChild($this->element('target')->build())
                            ->build(),
                    )
                    ->build(),
            )
            ->build();

        $result = NodeTraverser::findFirst($root, function (Node $node): bool {
            return $node instanceof ElementNode && $node->tag === 'target';
        });

        $this->assertInstanceOf(ElementNode::class, $result);

        $element = $result;
        $this->assertSame('target', $element->tag);
    }

    public function testFindAllReturnsAllMatches(): void
    {
        $root = $this->element('root')
            ->withChildren([
                $this->element('div')->build(),
                $this->text('Text', 1, 1),
                $this->element('span')->build(),
                $this->element('p')->build(),
            ])
            ->build();

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
        $root = $this->element('root')
            ->withChild($this->text('Only text', 1, 1))
            ->build();

        $result = NodeTraverser::findAll($root, function (Node $node): bool {
            return $node instanceof ElementNode && $node->tag === 'missing';
        });

        $this->assertSame([], $result);
    }

    public function testFindAllSearchesRecursively(): void
    {
        $root = $this->element('root')
            ->withChild(
                $this->element('div')
                    ->withChild(
                        $this->element('span')
                            ->withChild($this->element('p')->build())
                            ->build(),
                    )
                    ->build(),
            )
            ->build();

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
        /**
         * @return \Sugar\Ast\Node
         */
        $visitor = function (Node $node, callable $recurse): Node {
            /** @var callable(\Sugar\Ast\Node): \Sugar\Ast\Node $recurse */
            return $recurse($node);
        };

        $result = NodeTraverser::walk([], $visitor);

        $this->assertSame([], $result);
    }

    public function testWalkRecursiveHandlesEmptyArray(): void
    {
        $visited = false;
        $root = $this->element('root')->build();

        NodeTraverser::walkRecursive($root, function (Node $node) use (&$visited): void {
            if ($node instanceof TextNode) {
                $visited = true;
            }
        });

        $this->assertFalse($visited);
    }

    public function testFindFirstHandlesEmptyArray(): void
    {
        $root = $this->element('root')->build();

        $result = NodeTraverser::findFirst($root, function (Node $node): bool {
            return $node instanceof TextNode;
        });

        $this->assertNotInstanceOf(Node::class, $result);
    }

    public function testFindAllHandlesEmptyArray(): void
    {
        $root = $this->element('root')->build();

        $result = NodeTraverser::findAll($root, function (Node $node): bool {
            return $node instanceof TextNode;
        });

        $this->assertSame([], $result);
    }
}
