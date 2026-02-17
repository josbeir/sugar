<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\FinallyDirective;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Exception\SyntaxException;

final class FinallyDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new FinallyDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'finally';
    }

    public function testGetType(): void
    {
        $this->assertSame(DirectiveType::CONTROL_FLOW, $this->directiveCompiler->getType());
    }

    public function testCompileThrowsWithoutTry(): void
    {
        $node = $this->directive('finally')
            ->withChild($this->text('Cleanup'))
            ->at(1, 1)
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:finally must follow s:try');

        $this->directiveCompiler->compile($node, $this->createTestContext());
    }
}
