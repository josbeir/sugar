<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Ast\Trait;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;

final class SiblingNavigationTraitTest extends TestCase
{
    public function testGetNextSibling(): void
    {
        $child1 = new TextNode('first', 1, 1);
        $child2 = new TextNode('second', 1, 1);
        $child3 = new TextNode('third', 1, 1);

        $doc = new DocumentNode([$child1, $child2, $child3]);

        $this->assertSame($child2, $doc->getNextSibling($child1));
        $this->assertSame($child3, $doc->getNextSibling($child2));
        $this->assertNotInstanceOf(Node::class, $doc->getNextSibling($child3));
    }

    public function testGetPreviousSibling(): void
    {
        $child1 = new TextNode('first', 1, 1);
        $child2 = new TextNode('second', 1, 1);
        $child3 = new TextNode('third', 1, 1);

        $doc = new DocumentNode([$child1, $child2, $child3]);

        $this->assertNotInstanceOf(Node::class, $doc->getPreviousSibling($child1));
        $this->assertSame($child1, $doc->getPreviousSibling($child2));
        $this->assertSame($child2, $doc->getPreviousSibling($child3));
    }

    public function testFindNextSiblingWithPredicate(): void
    {
        $text1 = new TextNode('text1', 1, 1);
        $element = new ElementNode('div', [], [], false, 1, 1);
        $text2 = new TextNode('text2', 1, 1);

        $doc = new DocumentNode([$text1, $element, $text2]);

        // Find next ElementNode
        $result = $doc->findNextSibling($text1, fn($node): bool => $node instanceof ElementNode);
        $this->assertSame($element, $result);

        // Find next TextNode after element
        $result = $doc->findNextSibling($element, fn($node): bool => $node instanceof TextNode);
        $this->assertSame($text2, $result);
    }

    public function testFindNextSiblingReturnsNullWhenNotFound(): void
    {
        $text1 = new TextNode('text1', 1, 1);
        $text2 = new TextNode('text2', 1, 1);

        $doc = new DocumentNode([$text1, $text2]);

        // Try to find ElementNode that doesn't exist
        $result = $doc->findNextSibling($text1, fn($node): bool => $node instanceof ElementNode);
        $this->assertNotInstanceOf(Node::class, $result);
    }

    public function testFindNextSiblingReturnsNullForLastChild(): void
    {
        $text1 = new TextNode('text1', 1, 1);
        $text2 = new TextNode('text2', 1, 1);

        $doc = new DocumentNode([$text1, $text2]);

        // Last child has no next sibling
        $result = $doc->findNextSibling($text2, fn($node): bool => $node instanceof TextNode);
        $this->assertNotInstanceOf(Node::class, $result);
    }

    public function testGetNextSiblingReturnsNullForNonChild(): void
    {
        $child = new TextNode('child', 1, 1);
        $nonChild = new TextNode('nonchild', 1, 1);

        $doc = new DocumentNode([$child]);

        $this->assertNotInstanceOf(Node::class, $doc->getNextSibling($nonChild));
    }

    public function testGetPreviousSiblingReturnsNullForNonChild(): void
    {
        $child = new TextNode('child', 1, 1);
        $nonChild = new TextNode('nonchild', 1, 1);

        $doc = new DocumentNode([$child]);

        $this->assertNotInstanceOf(Node::class, $doc->getPreviousSibling($nonChild));
    }

    public function testFindNextSiblingReturnsNullForNonChild(): void
    {
        $child = new TextNode('child', 1, 1);
        $nonChild = new TextNode('nonchild', 1, 1);

        $doc = new DocumentNode([$child]);

        $result = $doc->findNextSibling($nonChild, fn($node): true => true);
        $this->assertNotInstanceOf(Node::class, $result);
    }
}
