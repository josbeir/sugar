<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Directive\ForeachDirective;
use Sugar\Directive\Interface\DirectiveInterface;

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
}
