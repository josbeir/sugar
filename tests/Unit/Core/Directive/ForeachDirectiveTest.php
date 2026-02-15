<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Directive\ForeachDirective;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Exception\SyntaxException;

final class ForeachDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new ForeachDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'foreach';
    }

    public function testCompileForeach(): void
    {
        $node = $this->directive('foreach')
            ->expression('$items as $item')
            ->withChild($this->text('Item'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(8)
            ->hasPhpCode('$__loopStack')
            ->hasPhpCode('LoopMetadata')
            ->hasPhpCode('foreach ($items as $item):')
            ->hasPhpCode('$loop->next()')
            ->hasPhpCode('endforeach;')
            ->hasPhpCode('array_pop($__loopStack)');
    }

    public function testCompileForeachWithKey(): void
    {
        $node = $this->directive('foreach')
            ->expression('$users as $id => $user')
            ->withChild($this->text('User'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(8)
            ->hasPhpCode('LoopMetadata($users')
            ->hasPhpCode('foreach ($users as $id => $user):');
    }

    public function testCompileForeachThrowsForMissingIterationExpression(): void
    {
        $node = $this->directive('foreach')
            ->expression('true')
            ->withChild($this->text('Item'))
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:foreach requires an expression like "$items as $item".');

        $this->directiveCompiler->compile($node, $this->createTestContext());
    }
}
