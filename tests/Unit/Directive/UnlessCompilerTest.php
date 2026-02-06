<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\UnlessCompiler;
use Sugar\Extension\DirectiveCompilerInterface;

final class UnlessCompilerTest extends DirectiveCompilerTestCase
{
    protected function getDirectiveCompiler(): DirectiveCompilerInterface
    {
        return new UnlessCompiler();
    }

    protected function getDirectiveName(): string
    {
        return 'unless';
    }

    public function testCompilesUnlessDirective(): void
    {
        $node = $this->directive('unless')
            ->expression('$isAdmin')
            ->withChild($this->text('Regular user content'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(3)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('if (!($isAdmin)):')
            ->containsNodeType(TextNode::class)
            ->hasPhpCode('endif;');
    }

    public function testUnlessWithComplexCondition(): void
    {
        $node = $this->directive('unless')
            ->expression('$user->isAdmin() && $user->isActive()')
            ->withChild($this->text('Content'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (!($user->isAdmin() && $user->isActive())):')
            ->hasPhpCode('endif;');
    }

    public function testUnlessWithEmptyCondition(): void
    {
        $node = $this->directive('unless')
            ->expression('empty($cart)')
            ->withChild($this->text('Cart is not empty'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('if (!(empty($cart))):');
    }

    public function testUnlessWithMultipleChildren(): void
    {
        $node = $this->directive('unless')
            ->expression('$hideContent')
            ->withChildren([
                $this->text('First line', 1),
                $this->text('Second line', 2),
            ])
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(4)
            ->hasPhpCode('if (!($hideContent)):')
            ->containsText('First line')
            ->containsText('Second line')
            ->hasPhpCode('endif;');
    }
}
