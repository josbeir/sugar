<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;

/**
 * Test AST node hierarchy
 */
final class NodeTest extends TestCase
{
    public function testTextNodeStoresContent(): void
    {
        $node = new TextNode('Hello World', 1, 5);

        $this->assertSame('Hello World', $node->content);
        $this->assertSame(1, $node->line);
        $this->assertSame(5, $node->column);
    }

    public function testOutputNodeStoresExpressionAndContext(): void
    {
        $node = new OutputNode(
            expression: '$userName',
            escape: true,
            context: OutputContext::HTML,
            line: 2,
            column: 10,
        );

        $this->assertSame('$userName', $node->expression);
        $this->assertTrue($node->escape);
        $this->assertSame(OutputContext::HTML, $node->context);
        $this->assertSame(2, $node->line);
        $this->assertSame(10, $node->column);
    }

    public function testOutputNodeWithRawContext(): void
    {
        $node = new OutputNode(
            expression: '$htmlContent',
            escape: false,
            context: OutputContext::RAW,
            line: 3,
            column: 1,
        );

        $this->assertSame('$htmlContent', $node->expression);
        $this->assertFalse($node->escape);
        $this->assertSame(OutputContext::RAW, $node->context);
    }

    public function testDocumentNodeStoresChildren(): void
    {
        $children = [
            new TextNode('Hello ', 1, 1),
            new OutputNode('$name', true, OutputContext::HTML, 1, 7),
            new TextNode('!', 1, 13),
        ];

        $doc = new DocumentNode($children, 1, 1);

        $this->assertSame($children, $doc->children);
        $this->assertCount(3, $doc->children);
    }

    public function testDocumentNodeCountsChildren(): void
    {
        $children = [
            new TextNode('Text 1', 1, 1),
            new TextNode('Text 2', 2, 1),
            new OutputNode('$var', true, OutputContext::HTML, 3, 1),
        ];

        $doc = new DocumentNode($children);

        $this->assertSame(3, $doc->count());
    }

    public function testEmptyDocumentNode(): void
    {
        $doc = new DocumentNode([]);

        $this->assertSame([], $doc->children);
        $this->assertSame(0, $doc->count());
    }
}
