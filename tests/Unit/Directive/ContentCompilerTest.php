<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\OutputNode;
use Sugar\Directive\ContentCompiler;
use Sugar\Enum\OutputContext;

final class ContentCompilerTest extends TestCase
{
    public function testCompilesTextDirectiveWithEscaping(): void
    {
        $compiler = new ContentCompiler(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$user->name',
            children: [],
            line: 1,
            column: 5,
        );

        $result = $compiler->compile($node);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertTrue($result[0]->escape);
    }

    public function testCompilesHtmlDirectiveWithoutEscaping(): void
    {
        $compiler = new ContentCompiler(escape: false, context: OutputContext::RAW);

        $node = new DirectiveNode(
            name: 'html',
            expression: '$content',
            children: [],
            line: 1,
            column: 5,
        );

        $result = $compiler->compile($node);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertFalse($result[0]->escape);
    }

    public function testGeneratesEscapedOutputForText(): void
    {
        $compiler = new ContentCompiler(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$variable',
            children: [],
            line: 2,
            column: 10,
        );

        $result = $compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertTrue($result[0]->escape);
        $this->assertSame('$variable', $result[0]->expression);
    }

    public function testGeneratesUnescapedOutputForHtml(): void
    {
        $compiler = new ContentCompiler(escape: false, context: OutputContext::RAW);

        $node = new DirectiveNode(
            name: 'html',
            expression: '$htmlContent',
            children: [],
            line: 2,
            column: 10,
        );

        $result = $compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertFalse($result[0]->escape);
        $this->assertSame('$htmlContent', $result[0]->expression);
    }

    public function testSetsHtmlContextForTextDirective(): void
    {
        $compiler = new ContentCompiler(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$content',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(OutputContext::HTML, $result[0]->context);
    }

    public function testForcesRawContextForHtmlDirective(): void
    {
        $compiler = new ContentCompiler(escape: false, context: OutputContext::RAW);

        $node = new DirectiveNode(
            name: 'html',
            expression: '$trustedHtml',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(OutputContext::RAW, $result[0]->context);
    }

    public function testPreservesLineAndColumnForTextDirective(): void
    {
        $compiler = new ContentCompiler(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$data',
            children: [],
            line: 5,
            column: 12,
        );

        $result = $compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(5, $result[0]->line);
        $this->assertSame(12, $result[0]->column);
    }

    public function testPreservesLineAndColumnForHtmlDirective(): void
    {
        $compiler = new ContentCompiler(escape: false, context: OutputContext::RAW);

        $node = new DirectiveNode(
            name: 'html',
            expression: '$data',
            children: [],
            line: 8,
            column: 15,
        );

        $result = $compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(8, $result[0]->line);
        $this->assertSame(15, $result[0]->column);
    }

    public function testHandlesComplexExpressionForTextDirective(): void
    {
        $compiler = new ContentCompiler(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$user->profile->getDisplayName()',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame('$user->profile->getDisplayName()', $result[0]->expression);
        $this->assertTrue($result[0]->escape);
    }

    public function testHandlesComplexExpressionForHtmlDirective(): void
    {
        $compiler = new ContentCompiler(escape: false, context: OutputContext::RAW);

        $node = new DirectiveNode(
            name: 'html',
            expression: '$formatter->renderHtml($data)',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame('$formatter->renderHtml($data)', $result[0]->expression);
        $this->assertFalse($result[0]->escape);
        $this->assertSame(OutputContext::RAW, $result[0]->context);
    }

    public function testAllowsCustomContextConfiguration(): void
    {
        $compiler = new ContentCompiler(escape: true, context: OutputContext::JAVASCRIPT);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$data',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node);

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(OutputContext::JAVASCRIPT, $result[0]->context);
        $this->assertTrue($result[0]->escape);
    }
}
