<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\SwitchDirective;
use Sugar\Core\Exception\SyntaxException;

final class SwitchDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new SwitchDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'switch';
    }

    public function testCompilesSwitchWithMultipleCases(): void
    {
        $switch = $this->directive('switch')
            ->expression('$role')
            ->withChildren([
                $this->directive('case')
                    ->expression("'admin'")
                    ->withChild($this->text('Admin Panel'))
                    ->at(2, 0)
                    ->build(),
                $this->directive('case')
                    ->expression("'moderator'")
                    ->withChild($this->text('Moderator Tools'))
                    ->at(3, 0)
                    ->build(),
            ])
            ->build();

        $result = $this->directiveCompiler->compile($switch, $this->createTestContext());

        $this->assertGreaterThanOrEqual(8, count($result));
        $this->assertAst($result)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('switch')
            ->hasPhpCode('$role');
    }

    public function testSwitchWithDefault(): void
    {
        $switch = $this->directive('switch')
            ->expression('$role')
            ->withChildren([
                $this->directive('case')
                    ->expression("'admin'")
                    ->withChild($this->text('Admin'))
                    ->at(2, 0)
                    ->build(),
                $this->directive('default')
                    ->withChild($this->text('User'))
                    ->at(3, 0)
                    ->build(),
            ])
            ->build();

        $result = $this->directiveCompiler->compile($switch, $this->createTestContext());

        $this->assertGreaterThanOrEqual(6, count($result));
        $this->assertAst($result)
            ->hasPhpCode('default:');
    }

    public function testSwitchWithoutCases(): void
    {
        $source = <<<'TEMPLATE'
<div s:switch="$value">No cases</div>
TEMPLATE;

        $switch = $this->directive('switch')
            ->expression('$value')
            ->withChild($this->text('No cases'))
            ->at(1, 5)
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Switch directive must contain at least one case or default');

        $this->directiveCompiler->compile($switch, $this->createTestContext($source));
    }

    public function testMultipleDefaultsThrowException(): void
    {
        $source = <<<'TEMPLATE'
<div s:switch="$role">
    <div s:default>First default</div>
    <div s:default>Second default</div>
</div>
TEMPLATE;

        $switch = $this->directive('switch')
            ->expression('$role')
            ->withChildren([
                $this->directive('default')
                    ->withChild($this->text('First default'))
                    ->at(2, 10)
                    ->build(),
                $this->directive('default')
                    ->withChild($this->text('Second default'))
                    ->at(3, 10)
                    ->build(),
            ])
            ->at(1, 5)
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Switch directive can only have one default case');

        $this->directiveCompiler->compile($switch, $this->createTestContext($source));
    }

    public function testSwitchWithMixedChildren(): void
    {
        $switch = $this->directive('switch')
            ->expression('$role')
            ->withChildren([
                $this->directive('case')
                    ->expression("'admin'")
                    ->withChild($this->text('Admin'))
                    ->at(2, 0)
                    ->build(),
                $this->text('Between cases'), // Non-directive child
                $this->directive('case')
                    ->expression("'user'")
                    ->withChild($this->text('User'))
                    ->at(3, 0)
                    ->build(),
            ])
            ->build();

        // Should ignore non-directive children between cases
        $result = $this->directiveCompiler->compile($switch, $this->createTestContext());
        $this->assertGreaterThan(1, count($result));
    }

    public function testCaseWithoutExpression(): void
    {
        $source = <<<'TEMPLATE'
<div s:switch="$role">
    <div s:case="">Content</div>
</div>
TEMPLATE;

        $switch = $this->directive('switch')
            ->expression('$role')
            ->withChild(
                $this->directive('case')
                    ->expression('')
                    ->withChild($this->text('Content'))
                    ->at(2, 10)
                    ->build(),
            )
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Case directive requires a value expression');

        $this->directiveCompiler->compile($switch, $this->createTestContext($source));
    }

    public function testCompilesNestedCasesInsideElementNodes(): void
    {
        // This tests the structure created by DirectiveExtractionPass from template syntax
        // <div s:switch="$role">
        //     <div s:case="'admin'">Admin</div>
        //     <div s:case="'user'">User</div>
        // </div>
        $switch = $this->directive('switch')
            ->expression('$role')
            ->withChildren([
                // After extraction, switch wrapper contains ElementNodes
                $this->element('div')
                    ->withChild(
                        // Case directive is nested inside ElementNode
                        $this->directive('case')
                            ->expression("'admin'")
                            ->withChild(
                                $this->element('div')
                                    ->withChild($this->text('Admin'))
                                    ->build(),
                            )
                            ->build(),
                    )
                    ->build(),
                $this->element('div')
                    ->withChild(
                        $this->directive('case')
                            ->expression("'user'")
                            ->withChild(
                                $this->element('div')
                                    ->withChild($this->text('User'))
                                    ->build(),
                            )
                            ->build(),
                    )
                    ->build(),
            ])
            ->build();

        $result = $this->directiveCompiler->compile($switch, $this->createTestContext());

        $this->assertGreaterThanOrEqual(8, count($result));
        $this->assertAst($result)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('switch');

        // Verify both cases are compiled
        $caseCount = 0;
        foreach ($result as $node) {
            if ($node instanceof RawPhpNode && str_contains($node->code, 'case ')) {
                $caseCount++;
            }
        }

        $this->assertSame(2, $caseCount);
    }

    public function testGetType(): void
    {
        $type = $this->directiveCompiler->getType();

        $this->assertSame(DirectiveType::CONTROL_FLOW, $type);
    }

    public function testNestedDefaultInsideElementNode(): void
    {
        $switch = $this->directive('switch')
            ->expression('$role')
            ->withChild(
                $this->element('div')
                    ->withChild(
                        $this->directive('default')
                            ->withChild($this->text('Default content'))
                            ->build(),
                    )
                    ->build(),
            )
            ->build();

        $result = $this->directiveCompiler->compile($switch, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('default:');
    }

    public function testMultipleNestedDefaultsThrowException(): void
    {
        $switch = $this->directive('switch')
            ->expression('$role')
            ->withChildren([
                $this->element('div')
                    ->withChild(
                        $this->directive('default')
                            ->withChild($this->text('First'))
                            ->build(),
                    )
                    ->build(),
                $this->element('div')
                    ->withChild(
                        $this->directive('default')
                            ->withChild($this->text('Second'))
                            ->at(2, 0)
                            ->build(),
                    )
                    ->at(2, 0)
                    ->build(),
            ])
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Switch directive can only have one default case');

        $this->directiveCompiler->compile($switch, $this->createTestContext());
    }

    public function testNestedCaseWithEmptyExpressionThrowsException(): void
    {
        $source = <<<'TEMPLATE'
<div s:switch="$role">
    <div s:case="   ">Content</div>
</div>
TEMPLATE;

        $switch = $this->directive('switch')
            ->expression('$role')
            ->withChild(
                $this->element('div')
                    ->withChild(
                        $this->directive('case')
                            ->expression('   ')
                            ->withChild($this->text('Content'))
                            ->at(2, 10)
                            ->build(),
                    )
                    ->build(),
            )
            ->at(1, 5)
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Case directive requires a value expression');

        $this->directiveCompiler->compile($switch, $this->createTestContext($source));
    }
}
