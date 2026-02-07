<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Ast\ElementNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Directive\Interface\DirectiveCompilerInterface;
use Sugar\Directive\TimesCompiler;
use Sugar\Enum\DirectiveType;
use Sugar\Exception\SyntaxException;

final class TimesCompilerTest extends DirectiveCompilerTestCase
{
    protected function getDirectiveCompiler(): DirectiveCompilerInterface
    {
        return new TimesCompiler();
    }

    protected function getDirectiveName(): string
    {
        return 'times';
    }

    public function testCompileTimes(): void
    {
        $node = $this->directive('times')
            ->expression('5')
            ->withChild($this->text('Item'))
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(3)
            ->hasPhpCode('for ($__times_')
            ->hasPhpCode('< (5)')
            ->hasPhpCode('endfor;');
    }

    public function testCompileTimesWithIndex(): void
    {
        $node = $this->directive('times')
            ->expression('5 as $i')
            ->withChild($this->text('Item'))
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(3)
            ->hasPhpCode('for ($i = 0; $i < (5); $i++):')
            ->hasPhpCode('endfor;');
    }

    public function testCompileTimesWithWrapperMode(): void
    {
        $innerElement = $this->element('span')
            ->withChild($this->text('Loop', 2, 5))
            ->at(2, 1)
            ->build();

        $wrapperElement = $this->element('div')
            ->withChild($innerElement)
            ->at(1, 1)
            ->build();

        $node = $this->directive('times')
            ->expression('3')
            ->withChild($wrapperElement)
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertSame('div', $result[0]->tag);

        $wrapperChildren = $result[0]->children;
        $this->assertNotEmpty($wrapperChildren);
        $this->assertInstanceOf(RawPhpNode::class, $wrapperChildren[0]);
        $this->assertStringContainsString('for ($__times_', $wrapperChildren[0]->code);
    }

    public function testCompileTimesRejectsInvalidIndex(): void
    {
        $node = $this->directive('times')
            ->expression('5 as i')
            ->withChild($this->text('Item'))
            ->at(1, 1)
            ->build();

        $this->expectException(SyntaxException::class);

        $this->directiveCompiler->compile($node, $this->createTestContext());
    }

    public function testCompileTimesRejectsEmptyExpression(): void
    {
        $node = $this->directive('times')
            ->expression('')
            ->withChild($this->text('Item'))
            ->at(1, 1)
            ->build();

        $this->expectException(SyntaxException::class);

        $this->directiveCompiler->compile($node, $this->createTestContext());
    }

    public function testGetType(): void
    {
        $this->assertSame(DirectiveType::CONTROL_FLOW, $this->directiveCompiler->getType());
    }
}
