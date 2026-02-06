<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Ast\RawPhpNode;
use Sugar\Directive\SpreadCompiler;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveCompilerInterface;
use Sugar\Runtime\HtmlAttributeHelper;

final class SpreadCompilerTest extends DirectiveCompilerTestCase
{
    protected function getDirectiveCompiler(): DirectiveCompilerInterface
    {
        return new SpreadCompiler();
    }

    protected function getDirectiveName(): string
    {
        return 'spread';
    }

    public function testCompilesSpreadDirective(): void
    {
        $node = $this->directive('spread')
            ->expression('$attrs')
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode(HtmlAttributeHelper::class . '::spreadAttrs')
            ->hasPhpCode('$attrs');
    }

    public function testGeneratesPhpOutput(): void
    {
        $node = $this->directive('spread')
            ->expression('$attributes')
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('<?=')
            ->hasPhpCode('?>');
    }

    public function testHandlesComplexExpressions(): void
    {
        $node = $this->directive('spread')
            ->expression('array_merge($baseAttrs, $customAttrs)')
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('array_merge($baseAttrs, $customAttrs)');
    }

    public function testGetType(): void
    {
        $this->assertSame(DirectiveType::ATTRIBUTE, $this->directiveCompiler->getType());
    }
}
