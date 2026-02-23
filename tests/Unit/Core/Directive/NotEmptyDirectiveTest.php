<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\NotEmptyDirective;
use Sugar\Core\Runtime\EmptyHelper;

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

    public function testNotEmptyWithArrayAccess(): void
    {
        $node = $this->directive('notempty')
            ->expression('$data[\'items\']')
            ->withChild($this->text('Has items'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (!' . EmptyHelper::class . '::isEmpty($data[\'items\'])):');
    }

    public function testNotEmptyWithPropertyAccess(): void
    {
        $node = $this->directive('notempty')
            ->expression('$user->posts')
            ->withChild($this->text('Has posts'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (!' . EmptyHelper::class . '::isEmpty($user->posts)):');
    }

    public function testNotEmptyWithMultipleChildren(): void
    {
        $node = $this->directive('notempty')
            ->expression('$results')
            ->withChildren([
                $this->text('Results found'),
                $this->text('Showing first page'),
            ])
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(4)
            ->hasPhpCode('if (!' . EmptyHelper::class . '::isEmpty($results)):')
            ->containsText('Results found')
            ->containsText('Showing first page')
            ->hasPhpCode('endif;');
    }

    public function testGetElementExpressionAttribute(): void
    {
        // NotEmptyDirective extends EmptyDirective and inherits the attribute name.
        $directive = new NotEmptyDirective();
        $this->assertSame('value', $directive->getElementExpressionAttribute());
    }

    public function testElementSyntaxCompilesToNotEmptyCheck(): void
    {
        $compiled = $this->compiler->compile('<s-notempty value="$items"><p>Has items</p></s-notempty>');

        $this->assertContainsPhp('!__SugarEmptyHelper::isEmpty($items)', $compiled);
        $this->assertContainsPhp('endif;', $compiled);
        $this->assertContainsPhp('<p>Has items</p>', $compiled);
    }
}
