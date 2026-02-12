<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\ContentDirective;
use Sugar\Enum\OutputContext;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class ContentDirectiveTest extends TestCase
{
    use TemplateTestHelperTrait;

    public function testCompilesTextDirectiveWithEscaping(): void
    {
        $compiler = new ContentDirective(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$user->name',
            children: [],
            line: 1,
            column: 5,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertCount(1, $result);
        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertTrue($result[0]->escape);
    }

    public function testCompilesHtmlDirectiveWithoutEscaping(): void
    {
        $compiler = new ContentDirective(escape: false, context: OutputContext::RAW);

        $node = new DirectiveNode(
            name: 'html',
            expression: '$content',
            children: [],
            line: 1,
            column: 5,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertCount(1, $result);
        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertFalse($result[0]->escape);
    }

    public function testGeneratesEscapedOutputForText(): void
    {
        $compiler = new ContentDirective(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$variable',
            children: [],
            line: 2,
            column: 10,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertTrue($result[0]->escape);
        $this->assertSame('$variable', $result[0]->expression);
    }

    public function testGeneratesUnescapedOutputForHtml(): void
    {
        $compiler = new ContentDirective(escape: false, context: OutputContext::RAW);

        $node = new DirectiveNode(
            name: 'html',
            expression: '$htmlContent',
            children: [],
            line: 2,
            column: 10,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertFalse($result[0]->escape);
        $this->assertSame('$htmlContent', $result[0]->expression);
    }

    public function testSetsHtmlContextForTextDirective(): void
    {
        $compiler = new ContentDirective(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$content',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(OutputContext::HTML, $result[0]->context);
    }

    public function testForcesRawContextForHtmlDirective(): void
    {
        $compiler = new ContentDirective(escape: false, context: OutputContext::RAW);

        $node = new DirectiveNode(
            name: 'html',
            expression: '$trustedHtml',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(OutputContext::RAW, $result[0]->context);
    }

    public function testPreservesLineAndColumnForTextDirective(): void
    {
        $compiler = new ContentDirective(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$data',
            children: [],
            line: 5,
            column: 12,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(5, $result[0]->line);
        $this->assertSame(12, $result[0]->column);
    }

    public function testPreservesLineAndColumnForHtmlDirective(): void
    {
        $compiler = new ContentDirective(escape: false, context: OutputContext::RAW);

        $node = new DirectiveNode(
            name: 'html',
            expression: '$data',
            children: [],
            line: 8,
            column: 15,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(8, $result[0]->line);
        $this->assertSame(15, $result[0]->column);
    }

    public function testHandlesComplexExpressionForTextDirective(): void
    {
        $compiler = new ContentDirective(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$user->profile->getDisplayName()',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame('$user->profile->getDisplayName()', $result[0]->expression);
        $this->assertTrue($result[0]->escape);
    }

    public function testHandlesComplexExpressionForHtmlDirective(): void
    {
        $compiler = new ContentDirective(escape: false, context: OutputContext::RAW);

        $node = new DirectiveNode(
            name: 'html',
            expression: '$formatter->renderHtml($data)',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame('$formatter->renderHtml($data)', $result[0]->expression);
        $this->assertFalse($result[0]->escape);
        $this->assertSame(OutputContext::RAW, $result[0]->context);
    }

    public function testAllowsCustomContextConfiguration(): void
    {
        $compiler = new ContentDirective(escape: true, context: OutputContext::JAVASCRIPT);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$data',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertSame(OutputContext::JAVASCRIPT, $result[0]->context);
        $this->assertTrue($result[0]->escape);
    }

    public function testCompileDisablesEscapingForRawPipe(): void
    {
        $compiler = new ContentDirective(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$content |> raw()',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertFalse($result[0]->escape);
        $this->assertSame(OutputContext::RAW, $result[0]->context);
    }

    public function testCompileUsesJsonContextForJsonPipe(): void
    {
        $compiler = new ContentDirective(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$content |> json()',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertTrue($result[0]->escape);
        $this->assertSame(OutputContext::JSON, $result[0]->context);
    }

    public function testCompileReplacesWrappedElementChildren(): void
    {
        $compiler = new ContentDirective(escape: true);

        $element = new ElementNode(
            tag: 'div',
            attributes: [],
            children: [new TextNode('Old', 1, 1)],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $node = new DirectiveNode(
            name: 'text',
            expression: '$content',
            children: [$element],
            line: 1,
            column: 1,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertCount(1, $result[0]->children);
        $this->assertInstanceOf(OutputNode::class, $result[0]->children[0]);
    }

    public function testCompileReturnsOutputAndChildrenForControlFlowCase(): void
    {
        $compiler = new ContentDirective(escape: true);

        $node = new DirectiveNode(
            name: 'text',
            expression: '$content',
            children: [new TextNode('Keep', 1, 1)],
            line: 1,
            column: 1,
        );

        $result = $compiler->compile($node, $this->createContext());

        $this->assertCount(2, $result);
        $this->assertInstanceOf(OutputNode::class, $result[0]);
        $this->assertInstanceOf(TextNode::class, $result[1]);
    }
}
