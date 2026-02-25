<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Directive\ClassDirective;
use Sugar\Core\Directive\Enum\AttributeMergeMode;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\AttributeMergePolicyDirectiveInterface;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Runtime\HtmlAttributeHelper;

final class ClassDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new ClassDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'class';
    }

    public function testCompilesClassDirective(): void
    {
        $node = new DirectiveNode(
            name: 'class',
            expression: "['btn', 'active' => \$isActive]",
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode(HtmlAttributeHelper::class . '::classNames')
            ->hasPhpCode("['btn', 'active' => \$isActive]");
    }

    public function testGeneratesClassAttribute(): void
    {
        $node = new DirectiveNode(
            name: 'class',
            expression: "['card', 'shadow']",
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('class="')
            ->hasPhpCode('<?=')
            ->hasPhpCode('?>');
    }

    public function testImplementsMergePolicyInterface(): void
    {
        $directive = new ClassDirective();

        $this->assertInstanceOf(AttributeMergePolicyDirectiveInterface::class, $directive);
        $this->assertSame(DirectiveType::ATTRIBUTE, $directive->getType());
    }

    public function testReturnsMergePolicyMetadata(): void
    {
        $directive = new ClassDirective();

        $this->assertSame(AttributeMergeMode::MERGE_NAMED, $directive->getAttributeMergeMode());
        $this->assertSame('class', $directive->getMergeTargetAttributeName());
    }

    public function testMergesNamedAttributeExpression(): void
    {
        $directive = new ClassDirective();
        $result = $directive->mergeNamedAttributeExpression("'card'", "HtmlAttributeHelper::classNames(['active' => \$active])");

        $this->assertStringContainsString(HtmlAttributeHelper::class . '::mergeClassValues', $result);
        $this->assertStringContainsString('classNames', $result);
        $this->assertStringContainsString("'card'", $result);
        $this->assertStringContainsString("'active' => \$active", $result);
    }

    public function testBuildExcludedAttributesExpressionReturnsSpreadCall(): void
    {
        $directive = new ClassDirective();
        $result = $directive->buildExcludedAttributesExpression('$attrs', ['id', 'class']);

        $this->assertSame(HtmlAttributeHelper::class . '::spreadAttrs($attrs)', $result);
    }
}
