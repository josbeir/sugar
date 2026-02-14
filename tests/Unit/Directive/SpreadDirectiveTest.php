<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Ast\RawPhpNode;
use Sugar\Directive\Interface\AttributeMergePolicyDirectiveInterface;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\SpreadDirective;
use Sugar\Enum\AttributeMergeMode;
use Sugar\Enum\DirectiveType;
use Sugar\Runtime\HtmlAttributeHelper;

final class SpreadDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new SpreadDirective();
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

    public function testImplementsMergePolicyInterface(): void
    {
        $this->assertInstanceOf(AttributeMergePolicyDirectiveInterface::class, new SpreadDirective());
    }

    public function testReturnsMergePolicyMetadata(): void
    {
        $directive = new SpreadDirective();

        $this->assertSame(AttributeMergeMode::EXCLUDE_NAMED, $directive->getAttributeMergeMode());
        $this->assertNull($directive->getMergeTargetAttributeName());
    }

    public function testMergeNamedAttributeExpressionReturnsIncomingExpression(): void
    {
        $directive = new SpreadDirective();

        $this->assertSame('incoming', $directive->mergeNamedAttributeExpression('existing', 'incoming'));
    }

    public function testBuildExcludedAttributesExpressionWithoutExcludedNames(): void
    {
        $directive = new SpreadDirective();
        $result = $directive->buildExcludedAttributesExpression('$attrs', []);

        $this->assertSame(HtmlAttributeHelper::class . '::spreadAttrs($attrs)', $result);
    }

    public function testBuildExcludedAttributesExpressionWithExcludedNames(): void
    {
        $directive = new SpreadDirective();
        $result = $directive->buildExcludedAttributesExpression('$attrs', ['id', 'class']);

        $this->assertStringContainsString(HtmlAttributeHelper::class . '::spreadAttrs(array_diff_key((array) ($attrs), [', $result);
        $this->assertStringContainsString("'id' => true", $result);
        $this->assertStringContainsString("'class' => true", $result);
    }
}
