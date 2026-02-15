<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\UnlessDirective;

final class UnlessDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new UnlessDirective();
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
