<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\IssetDirective;

final class IssetDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new IssetDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'isset';
    }

    public function testCompilesIssetDirective(): void
    {
        $node = $this->directive('isset')
            ->expression('$user')
            ->withChild($this->text('User exists'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(3)
            ->hasPhpCode('if (isset($user)):')
            ->containsText('User exists')
            ->hasPhpCode('endif;');
    }

    public function testIssetWithMultipleVariables(): void
    {
        $node = $this->directive('isset')
            ->expression('$user, $profile')
            ->withChild($this->text('Both exist'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (isset($user, $profile)):');
    }

    public function testIssetWithArrayAccess(): void
    {
        $node = $this->directive('isset')
            ->expression('$data[\'key\']')
            ->withChild($this->text('Key exists'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (isset($data[\'key\'])):');
    }

    public function testIssetWithPropertyAccess(): void
    {
        $node = $this->directive('isset')
            ->expression('$user->email')
            ->withChild($this->text('Email exists'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (isset($user->email)):');
    }

    public function testIssetWithMultipleChildren(): void
    {
        $node = $this->directive('isset')
            ->expression('$content')
            ->withChildren([
                $this->text('First line'),
                $this->text('Second line'),
            ])
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(4)
            ->hasPhpCode('if (isset($content)):')
            ->containsText('First line')
            ->containsText('Second line')
            ->hasPhpCode('endif;');
    }

    public function testGetElementExpressionAttribute(): void
    {
        $directive = new IssetDirective();
        $this->assertSame('value', $directive->getElementExpressionAttribute());
    }

    public function testElementSyntaxCompilesToIssetCheck(): void
    {
        $compiled = $this->compiler->compile('<s-isset value="$user"><p>Hi</p></s-isset>');

        $this->assertContainsPhp('if (isset($user)):', $compiled);
        $this->assertContainsPhp('endif;', $compiled);
        $this->assertContainsPhp('<p>Hi</p>', $compiled);
    }
}
