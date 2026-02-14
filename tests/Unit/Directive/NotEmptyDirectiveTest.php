<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\NotEmptyDirective;
use Sugar\Runtime\EmptyHelper;

final class NotEmptyDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new NotEmptyDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'notempty';
    }

    public function testCompilesNotEmptyDirective(): void
    {
        $node = $this->directive('notempty')
            ->expression('$cart')
            ->withChild($this->text('Cart has items'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(3)
            ->hasPhpCode('if (!' . EmptyHelper::class . '::isEmpty($cart)):')
            ->containsText('Cart has items')
            ->hasPhpCode('endif;');
    }

    public function testNotEmptyWithComplexExpression(): void
    {
        $node = $this->directive('notempty')
            ->expression('trim($input)')
            ->withChild($this->text('Input has content'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (!' . EmptyHelper::class . '::isEmpty(trim($input))):');
    }
}
