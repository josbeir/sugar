<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\OutputNode;
use Sugar\Directive\HtmlCompiler;
use Sugar\Enum\OutputContext;

final class HtmlCompilerTest extends TestCase
{
    private HtmlCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new HtmlCompiler();
    }

    public function testCompilesHtmlDirective(): void
    {
        $node = new DirectiveNode(
            name: 'html',
            expression: '$content',
            children: [],
            line: 1,
            column: 5,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(OutputNode::class, $result[0]);
    }

    public function testGeneratesUnescapedOutput(): void
    {
        $node = new DirectiveNode(
            name: 'html',
            expression: '$htmlContent',
            children: [],
            line: 2,
            column: 10,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertFalse($result[0]->escape);
        $this->assertSame('$htmlContent', $result[0]->expression);
    }

    public function testForcesRawContext(): void
    {
        $node = new DirectiveNode(
            name: 'html',
            expression: '$trustedHtml',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(OutputContext::RAW, $result[0]->context);
    }

    public function testPreservesLineAndColumn(): void
    {
        $node = new DirectiveNode(
            name: 'html',
            expression: '$data',
            children: [],
            line: 8,
            column: 15,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(8, $result[0]->line);
        $this->assertSame(15, $result[0]->column);
    }

    public function testHandlesComplexExpression(): void
    {
        $node = new DirectiveNode(
            name: 'html',
            expression: '$formatter->renderHtml($data)',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame('$formatter->renderHtml($data)', $result[0]->expression);
        $this->assertFalse($result[0]->escape);
        $this->assertSame(OutputContext::RAW, $result[0]->context);
    }
}
