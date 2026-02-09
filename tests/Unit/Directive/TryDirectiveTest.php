<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\TryDirective;
use Sugar\Enum\DirectiveType;

final class TryDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new TryDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'try';
    }

    public function testGetType(): void
    {
        $this->assertSame(DirectiveType::CONTROL_FLOW, $this->directiveCompiler->getType());
    }

    public function testGetPairingDirective(): void
    {
        /** @var \Sugar\Directive\TryDirective $compiler */
        $compiler = $this->directiveCompiler;
        $this->assertSame('finally', $compiler->getPairingDirective());
    }

    public function testCompileTryWithoutFinallyAddsCatchRethrow(): void
    {
        $node = $this->directive('try')
            ->withChild($this->text('Run'))
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(5)
            ->hasPhpCode('try {')
            ->hasPhpCode('} catch (\\Throwable $__e) {')
            ->hasPhpCode('return null;')
            ->hasPhpCode('}');
    }

    public function testCompileTryWithFinally(): void
    {
        $finallyNode = $this->directive('finally')
            ->withChild($this->text('Cleanup'))
            ->at(2, 1)
            ->build();

        $tryNode = $this->directive('try')
            ->withChild($this->text('Run'))
            ->at(1, 1)
            ->build();

        $tryNode->setPairedSibling($finallyNode);

        $result = $this->directiveCompiler->compile($tryNode, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(5)
            ->hasPhpCode('try {')
            ->hasPhpCode('} finally {')
            ->hasPhpCode('}');
    }
}
