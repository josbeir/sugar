<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\WhileCompiler;
use Sugar\Enum\DirectiveType;
use Sugar\Enum\OutputContext;

final class WhileCompilerTest extends TestCase
{
    private WhileCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new WhileCompiler();
    }

    public function testCompileWhile(): void
    {
        $node = new DirectiveNode(
            name: 'while',
            expression: '$counter < 10',
            children: [new TextNode('Loop', 1, 1)],
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(3, $result); // while, content, endwhile
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('while ($counter < 10):', $result[0]->code);
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('endwhile;', $result[2]->code);
    }

    public function testCompileWhileWithWrapperMode(): void
    {
        // Wrapper mode requires the element has ElementNode children (not leaf)
        $innerElement = new ElementNode(
            tag: 'span',
            attributes: [],
            children: [
                new TextNode('Loop content', 2, 10),
                new OutputNode('$counter++', true, OutputContext::HTML, 2, 25),
            ],
            selfClosing: false,
            line: 2,
            column: 5,
        );

        $wrapperElement = new ElementNode(
            tag: 'div',
            attributes: [],
            children: [$innerElement], // Has ElementNode child = wrapper mode
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $node = new DirectiveNode(
            name: 'while',
            expression: '$counter < 5',
            children: [$wrapperElement],
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        // Wrapper mode: should return 1 element with while/endwhile inside
        $this->assertCount(1, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertSame('div', $result[0]->tag);

        // Check wrapper's children contain while/endwhile and original content
        $wrapperChildren = $result[0]->children;
        $this->assertGreaterThanOrEqual(3, count($wrapperChildren));
        $this->assertInstanceOf(RawPhpNode::class, $wrapperChildren[0]);
        $this->assertStringContainsString('while ($counter < 5):', $wrapperChildren[0]->code);
    }

    public function testCompileWhileWithComplexExpression(): void
    {
        $node = new DirectiveNode(
            name: 'while',
            expression: '($i < 10) && !empty($items[$i])',
            children: [new TextNode('Content', 1, 1)],
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('while (($i < 10) && !empty($items[$i])):', $result[0]->code);
    }

    public function testCompileWhileWithMultipleChildren(): void
    {
        $node = new DirectiveNode(
            name: 'while',
            expression: '$count > 0',
            children: [
                new TextNode('Line 1', 1, 1),
                new OutputNode('$count--', true, OutputContext::HTML, 1, 10),
                new TextNode('Line 2', 1, 20),
            ],
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(5, $result); // while, text, output, text, endwhile
        $this->assertInstanceOf(TextNode::class, $result[1]);
        $this->assertInstanceOf(OutputNode::class, $result[2]);
        $this->assertInstanceOf(TextNode::class, $result[3]);
    }

    public function testGetType(): void
    {
        $type = $this->compiler->getType();

        $this->assertSame(DirectiveType::CONTROL_FLOW, $type);
    }

    public function testCompileWhileWithEmptyChildren(): void
    {
        $node = new DirectiveNode(
            name: 'while',
            expression: 'true',
            children: [],
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(2, $result); // while, endwhile (no content)
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertInstanceOf(RawPhpNode::class, $result[1]);
    }
}
