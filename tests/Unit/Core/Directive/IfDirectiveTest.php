<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Directive\IfDirective;
use Sugar\Core\Directive\Interface\DirectiveInterface;

final class IfDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new IfDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'if';
    }

    public function testCompileIf(): void
    {
        $node = new DirectiveNode(
            name: 'if',
            expression: '$showContent',
            children: [$this->createTextNode('Content')],
            line: 1,
            column: 1,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(3)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('if ($showContent):')
            ->hasPhpCode('endif;');
    }

    public function testCompileElseif(): void
    {
        $node = new DirectiveNode(
            name: 'elseif',
            expression: '$otherCondition',
            children: [$this->createTextNode('Other')],
            line: 1,
            column: 1,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(2)
            ->hasPhpCode('elseif ($otherCondition):');
    }

    public function testCompileElse(): void
    {
        $node = new DirectiveNode(
            name: 'else',
            expression: '',
            children: [$this->createTextNode('Fallback')],
            line: 1,
            column: 1,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(2)
            ->hasPhpCode('else:');
    }

    public function testCompileIfWithElseBranch(): void
    {
        $ifNode = $this->directive('if')
            ->expression('$condition')
            ->withChild($this->text('True'))
            ->at(1, 1)
            ->build();

        $elseNode = $this->directive('else')
            ->withChild($this->text('False'))
            ->at(2, 1)
            ->build();

        $ifNode->setPairedSibling($elseNode);

        $result = $this->directiveCompiler->compile($ifNode, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(5)
            ->hasPhpCode('if ($condition):')
            ->containsText('True')
            ->hasPhpCode('else:')
            ->containsText('False')
            ->hasPhpCode('endif;');
    }
}
