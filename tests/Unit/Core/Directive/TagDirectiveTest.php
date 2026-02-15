<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\TagDirective;

final class TagDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new TagDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'tag';
    }

    public function testCompilesTagDirectiveWithSimpleVariable(): void
    {
        $node = new DirectiveNode(
            name: 'tag',
            expression: '$tagName',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('$tagName');
    }

    public function testCompilesTagDirectiveWithExpression(): void
    {
        $node = new DirectiveNode(
            name: 'tag',
            expression: '$semantic ? \'section\' : \'div\'',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('$semantic ? \'section\' : \'div\'');
    }

    public function testCompilesTagDirectiveWithConcatenation(): void
    {
        $node = new DirectiveNode(
            name: 'tag',
            expression: '\'h\' . $level',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('\'h\' . $level');
    }

    public function testGeneratesTagNameValidation(): void
    {
        $node = new DirectiveNode(
            name: 'tag',
            expression: '$tagName',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('HtmlTagHelper::validateTagName');
    }

    public function testStoresTagNameInVariable(): void
    {
        $node = new DirectiveNode(
            name: 'tag',
            expression: '$tagName',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('$__tag_');
    }
}
