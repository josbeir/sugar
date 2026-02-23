<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Directive\EmptyDirective;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Runtime\EmptyHelper;

final class EmptyDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new EmptyDirective();
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

    public function testGetElementExpressionAttribute(): void
    {
        $directive = new EmptyDirective();
        $this->assertSame('value', $directive->getElementExpressionAttribute());
    }

    public function testElementSyntaxCompilesToEmptyCheck(): void
    {
        $compiled = $this->compiler->compile('<s-empty value="$list"><p>No items</p></s-empty>');

        $this->assertContainsPhp('__SugarEmptyHelper::isEmpty($list)', $compiled);
        $this->assertContainsPhp('endif;', $compiled);
        $this->assertContainsPhp('<p>No items</p>', $compiled);
    }
}
