<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\SwitchCompiler;

final class SwitchCompilerTest extends TestCase
{
    private SwitchCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new SwitchCompiler();
    }

    public function testCompilesSwitchWithMultipleCases(): void
    {
        $switch = new DirectiveNode(
            name: 'switch',
            expression: '$role',
            children: [
                new DirectiveNode(
                    name: 'case',
                    expression: "'admin'",
                    children: [new TextNode('Admin Panel', 1, 0)],
                    elseChildren: null,
                    line: 2,
                    column: 0,
                ),
                new DirectiveNode(
                    name: 'case',
                    expression: "'moderator'",
                    children: [new TextNode('Moderator Tools', 1, 0)],
                    elseChildren: null,
                    line: 3,
                    column: 0,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($switch);

        // Should generate: switch, case admin, content, break, case moderator, content, break, endswitch
        $this->assertGreaterThanOrEqual(8, count($result));
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('switch', $result[0]->code);
        $this->assertStringContainsString('$role', $result[0]->code);
    }

    public function testSwitchWithDefault(): void
    {
        $switch = new DirectiveNode(
            name: 'switch',
            expression: '$role',
            children: [
                new DirectiveNode(
                    name: 'case',
                    expression: "'admin'",
                    children: [new TextNode('Admin', 1, 0)],
                    elseChildren: null,
                    line: 2,
                    column: 0,
                ),
                new DirectiveNode(
                    name: 'default',
                    expression: '',
                    children: [new TextNode('User', 1, 0)],
                    elseChildren: null,
                    line: 3,
                    column: 0,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($switch);

        $this->assertGreaterThanOrEqual(6, count($result));

        // Find the default case in output
        $hasDefault = false;
        foreach ($result as $node) {
            if ($node instanceof RawPhpNode && str_contains($node->code, 'default:')) {
                $hasDefault = true;
                break;
            }
        }
        $this->assertTrue($hasDefault);
    }

    public function testSwitchWithoutCases(): void
    {
        $switch = new DirectiveNode(
            name: 'switch',
            expression: '$value',
            children: [new TextNode('No cases', 1, 0)],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Switch directive must contain at least one case or default');

        $this->compiler->compile($switch);
    }

    public function testMultipleDefaultsThrowException(): void
    {
        $switch = new DirectiveNode(
            name: 'switch',
            expression: '$role',
            children: [
                new DirectiveNode(
                    name: 'default',
                    expression: '',
                    children: [new TextNode('First default', 1, 0)],
                    elseChildren: null,
                    line: 2,
                    column: 0,
                ),
                new DirectiveNode(
                    name: 'default',
                    expression: '',
                    children: [new TextNode('Second default', 1, 0)],
                    elseChildren: null,
                    line: 3,
                    column: 0,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Switch directive can only have one default case');

        $this->compiler->compile($switch);
    }

    public function testSwitchWithMixedChildren(): void
    {
        $switch = new DirectiveNode(
            name: 'switch',
            expression: '$role',
            children: [
                new DirectiveNode(
                    name: 'case',
                    expression: "'admin'",
                    children: [new TextNode('Admin', 1, 0)],
                    elseChildren: null,
                    line: 2,
                    column: 0,
                ),
                new TextNode('Between cases', 1, 0), // Non-directive child
                new DirectiveNode(
                    name: 'case',
                    expression: "'user'",
                    children: [new TextNode('User', 1, 0)],
                    elseChildren: null,
                    line: 3,
                    column: 0,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        // Should ignore non-directive children between cases
        $result = $this->compiler->compile($switch);
        $this->assertIsArray($result);
    }

    public function testCaseWithoutExpression(): void
    {
        $switch = new DirectiveNode(
            name: 'switch',
            expression: '$role',
            children: [
                new DirectiveNode(
                    name: 'case',
                    expression: '',
                    children: [new TextNode('Content', 1, 0)],
                    elseChildren: null,
                    line: 2,
                    column: 0,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Case directive requires a value expression');

        $this->compiler->compile($switch);
    }
}
