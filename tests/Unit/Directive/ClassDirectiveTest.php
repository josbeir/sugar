<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Directive\ClassDirective;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Runtime\HtmlAttributeHelper;

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
}
