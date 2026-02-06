<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive\Compiler;

use Sugar\Directive\ForeachCompiler;
use Sugar\Extension\DirectiveCompilerInterface;
use Sugar\Tests\Unit\Directive\DirectiveCompilerTestCase;

final class ForeachCompilerTest extends DirectiveCompilerTestCase
{
    protected function getDirectiveCompiler(): DirectiveCompilerInterface
    {
        return new ForeachCompiler();
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
