<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Ast\ElementNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\WhileDirective;
use Sugar\Enum\DirectiveType;
use Sugar\Enum\OutputContext;

final class WhileDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new WhileDirective();
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
        $innerElement = $this->element('span')
            ->withChildren([
                $this->text('Loop content', 2, 10),
                $this->outputNode('$counter++', true, OutputContext::HTML, 2, 25),
            ])
            ->at(2, 5)
            ->build();

        $wrapperElement = $this->element('div')
            ->withChild($innerElement) // Has ElementNode child = wrapper mode
            ->at(1, 1)
            ->build();

        $node = $this->directive('while')
            ->expression('$counter < 5')
            ->withChild($wrapperElement)
            ->at(1, 1)
            ->build();

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
        $this->assertSame(DirectiveType::CONTROL_FLOW, $this->directiveCompiler->getType());
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
