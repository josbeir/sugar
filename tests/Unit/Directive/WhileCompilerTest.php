<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive\Compiler;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\WhileCompiler;
use Sugar\Enum\DirectiveType;
use Sugar\Enum\OutputContext;
use Sugar\Extension\DirectiveCompilerInterface;
use Sugar\Tests\Unit\Directive\DirectiveCompilerTestCase;

final class WhileCompilerTest extends DirectiveCompilerTestCase
{
    protected function getDirectiveCompiler(): DirectiveCompilerInterface
    {
        return new WhileCompiler();
    }

    protected function getDirectiveName(): string
    {
        return 'while';
    }

    public function testCompileWhile(): void
    {
        $node = $this->directive('while')
            ->expression('$counter < 10')
            ->withChild($this->text('Loop'))
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(3)
            ->hasPhpCode('while ($counter < 10):')
            ->hasPhpCode('endwhile;');
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

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

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
        $node = $this->directive('while')
            ->expression('($i < 10) && !empty($items[$i])')
            ->withChild($this->text('Content'))
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('while (($i < 10) && !empty($items[$i])):');
    }

    public function testCompileWhileWithMultipleChildren(): void
    {
        $node = $this->directive('while')
            ->expression('$count > 0')
            ->withChildren([
                $this->text('Line 1'),
                $this->outputNode('$count--', true, OutputContext::HTML, 1, 10),
                $this->text('Line 2', 1, 20),
            ])
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(5)
            ->hasPhpCode('while ($count > 0):')
            ->containsText('Line 1')
            ->containsOutput()
            ->containsText('Line 2')
            ->hasPhpCode('endwhile;');
    }

    public function testGetType(): void
    {
        $type = $this->directiveCompiler->getType();

        $this->assertSame(DirectiveType::CONTROL_FLOW, $type);
    }

    public function testCompileWhileWithEmptyChildren(): void
    {
        $node = $this->directive('while')
            ->expression('true')
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(2)
            ->hasPhpCode('while (true):')
            ->hasPhpCode('endwhile;');
    }
}
