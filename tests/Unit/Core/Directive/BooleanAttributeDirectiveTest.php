<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Directive\BooleanAttributeDirective;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Runtime\HtmlAttributeHelper;

final class BooleanAttributeDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new BooleanAttributeDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'checked';
    }

    public function testGetType(): void
    {
        $this->assertSame(DirectiveType::ATTRIBUTE, $this->directiveCompiler->getType());
    }

    public function testCompilesCheckedDirective(): void
    {
        $node = $this->directive('checked')
            ->expression('$isSubscribed')
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode(HtmlAttributeHelper::class . '::booleanAttribute(')
            ->hasPhpCode("'checked'")
            ->hasPhpCode('$isSubscribed');
    }

    public function testCompilesSelectedDirective(): void
    {
        $node = $this->directive('selected')
            ->expression('$value === \'premium\'')
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode(HtmlAttributeHelper::class . '::booleanAttribute(')
            ->hasPhpCode("'selected'")
            ->hasPhpCode('$value === \'premium\'');
    }

    public function testCompilesDisabledDirective(): void
    {
        $node = $this->directive('disabled')
            ->expression('$isProcessing')
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode(HtmlAttributeHelper::class . '::booleanAttribute(')
            ->hasPhpCode("'disabled'")
            ->hasPhpCode('$isProcessing');
    }

    public function testHandlesComplexExpressions(): void
    {
        $node = $this->directive('checked')
            ->expression('in_array($item, $selected)')
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('in_array($item, $selected)');
    }
}
