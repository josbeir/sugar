<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\EmptyCompiler;

final class EmptyCompilerTest extends TestCase
{
    private EmptyCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new EmptyCompiler();
    }

    public function testCompilesEmptyDirective(): void
    {
        $node = new DirectiveNode(
            name: 'empty',
            expression: '$cart',
            children: [new TextNode('Cart is empty', 1, 0)],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(3, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (empty($cart)):', $result[0]->code);
        $this->assertInstanceOf(TextNode::class, $result[1]);
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertSame('endif;', $result[2]->code);
    }

    public function testEmptyWithArrayAccess(): void
    {
        $node = new DirectiveNode(
            name: 'empty',
            expression: '$data[\'items\']',
            children: [new TextNode('No items', 1, 0)],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (empty($data[\'items\'])):', $result[0]->code);
    }

    public function testEmptyWithPropertyAccess(): void
    {
        $node = new DirectiveNode(
            name: 'empty',
            expression: '$user->posts',
            children: [new TextNode('No posts', 1, 0)],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (empty($user->posts)):', $result[0]->code);
    }

    public function testEmptyWithComplexExpression(): void
    {
        $node = new DirectiveNode(
            name: 'empty',
            expression: 'trim($input)',
            children: [new TextNode('Input is empty', 1, 0)],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (empty(trim($input))):', $result[0]->code);
    }

    public function testEmptyWithMultipleChildren(): void
    {
        $node = new DirectiveNode(
            name: 'empty',
            expression: '$results',
            children: [
                new TextNode('No results found', 1, 0),
                new TextNode('Try a different search', 2, 0),
            ],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(4, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]); // if
        $this->assertInstanceOf(TextNode::class, $result[1]); // first child
        $this->assertInstanceOf(TextNode::class, $result[2]); // second child
        $this->assertInstanceOf(RawPhpNode::class, $result[3]); // endif
    }
}
