<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Directive\IfBlockDirective;
use Sugar\Core\Directive\Interface\DirectiveInterface;

final class IfBlockDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new IfBlockDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'ifblock';
    }

    public function testCompilesIfBlockDirective(): void
    {
        $node = $this->directive('ifblock')
            ->expression("'sidebar'")
            ->withChild($this->text('Sidebar content'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(3)
            ->hasPhpCode('if (')
            ->hasPhpCode('::requireService(')
            ->hasPhpCode("->hasDefinedBlock('sidebar')")
            ->containsText('Sidebar content')
            ->hasPhpCode('endif;');
    }

    public function testCompilesBareBlockNameAsStringLiteral(): void
    {
        $node = $this->directive('ifblock')
            ->expression('sidebar')
            ->withChild($this->text('Sidebar content'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode("->hasDefinedBlock('sidebar')");
    }

    public function testGetElementExpressionAttribute(): void
    {
        $directive = new IfBlockDirective();

        $this->assertSame('name', $directive->getElementExpressionAttribute());
    }

    public function testElementSyntaxCompilesToBlockDefinitionCheck(): void
    {
        $compiled = $this->compiler->compile('<s-ifblock name="sidebar"><aside>Visible</aside></s-ifblock>');

        $this->assertContainsPhp("->hasDefinedBlock('sidebar')", $compiled);
        $this->assertContainsPhp('endif;', $compiled);
        $this->assertContainsPhp('<aside>Visible</aside>', $compiled);
    }
}
