<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\OutputNode;
use Sugar\Directive\TextCompiler;
use Sugar\Enum\OutputContext;

final class TextCompilerTest extends TestCase
{
    private TextCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new TextCompiler();
    }

    public function testCompilesTextDirective(): void
    {
        $node = new DirectiveNode(
            name: 'text',
            expression: '$user->name',
            children: [],
            line: 1,
            column: 5,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(OutputNode::class, $result[0]);
    }

    public function testGeneratesEscapedOutput(): void
    {
        $node = new DirectiveNode(
            name: 'text',
            expression: '$variable',
            children: [],
            line: 2,
            column: 10,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertTrue($result[0]->escape);
        $this->assertSame('$variable', $result[0]->expression);
    }

    public function testSetsHtmlContext(): void
    {
        $node = new DirectiveNode(
            name: 'text',
            expression: '$content',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(OutputContext::HTML, $result[0]->context);
    }

    public function testPreservesLineAndColumn(): void
    {
        $node = new DirectiveNode(
            name: 'text',
            expression: '$data',
            children: [],
            line: 5,
            column: 12,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(5, $result[0]->line);
        $this->assertSame(12, $result[0]->column);
    }

    public function testHandlesComplexExpression(): void
    {
        $node = new DirectiveNode(
            name: 'text',
            expression: '$user->profile->getDisplayName()',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame('$user->profile->getDisplayName()', $result[0]->expression);
        $this->assertTrue($result[0]->escape);
    }
}
