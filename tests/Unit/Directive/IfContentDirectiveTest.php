<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Directive\IfContentDirective;
use Sugar\Directive\Interface\DirectiveInterface;

final class IfContentDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new IfContentDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'ifcontent';
    }

    public function testCompilesIfContentDirective(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCountGreaterThan(3) // At least: ob_start, capture+if, echo, endif
            ->containsNodeType(RawPhpNode::class);
    }

    public function testStartsOutputBuffering(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCountGreaterThan(3)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('ob_start()');
    }

    public function testStoresContentInVariable(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCountGreaterThan(3)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('$__content_');
    }

    public function testChecksForEmptyContent(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCountGreaterThan(3)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('trim')
            ->hasPhpCode("!== ''");
    }

    public function testUsesObGetClean(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCountGreaterThan(3)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('ob_get_clean()');
    }
}
