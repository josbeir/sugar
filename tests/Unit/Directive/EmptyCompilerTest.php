<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Directive\EmptyCompiler;
use Sugar\Directive\Interface\DirectiveCompilerInterface;
use Sugar\Runtime\EmptyHelper;

final class EmptyCompilerTest extends DirectiveCompilerTestCase
{
    protected function getDirectiveCompiler(): DirectiveCompilerInterface
    {
        return new EmptyCompiler();
    }

    protected function getDirectiveName(): string
    {
        return 'empty';
    }

    public function testCompilesEmptyDirective(): void
    {
        $node = $this->directive('empty')
            ->expression('$cart')
            ->withChild($this->text('Cart is empty'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(3)
            ->hasPhpCode('if (' . EmptyHelper::class . '::isEmpty($cart)):')
            ->containsText('Cart is empty')
            ->hasPhpCode('endif;');
    }

    public function testEmptyWithArrayAccess(): void
    {
        $node = $this->directive('empty')
            ->expression('$data[\'items\']')
            ->withChild($this->text('No items'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (' . EmptyHelper::class . '::isEmpty($data[\'items\'])):');
    }

    public function testEmptyWithPropertyAccess(): void
    {
        $node = $this->directive('empty')
            ->expression('$user->posts')
            ->withChild($this->text('No posts'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (' . EmptyHelper::class . '::isEmpty($user->posts)):');
    }

    public function testEmptyWithComplexExpression(): void
    {
        $node = $this->directive('empty')
            ->expression('trim($input)')
            ->withChild($this->text('Input is empty'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (' . EmptyHelper::class . '::isEmpty(trim($input))):');
    }

    public function testEmptyWithMultipleChildren(): void
    {
        $node = $this->directive('empty')
            ->expression('$results')
            ->withChildren([
                $this->text('No results found'),
                $this->text('Try a different search'),
            ])
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(4)
            ->hasPhpCode('if (' . EmptyHelper::class . '::isEmpty($results)):')
            ->containsText('No results found')
            ->containsText('Try a different search')
            ->hasPhpCode('endif;');
    }
}
