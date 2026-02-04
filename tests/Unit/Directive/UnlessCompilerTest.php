<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\UnlessCompiler;
use Sugar\Tests\TemplateTestHelperTrait;

final class UnlessCompilerTest extends TestCase
{
    use TemplateTestHelperTrait;

    private UnlessCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new UnlessCompiler();
    }

    public function testCompilesUnlessDirective(): void
    {
        $node = new DirectiveNode(
            name: 'unless',
            expression: '$isAdmin',
            children: [new TextNode('Regular user content', 1, 0)],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node, $this->createContext());

        $this->assertCount(3, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (!($isAdmin)):', $result[0]->code);
        $this->assertInstanceOf(TextNode::class, $result[1]);
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertSame('endif;', $result[2]->code);
    }

    public function testUnlessWithComplexCondition(): void
    {
        $node = new DirectiveNode(
            name: 'unless',
            expression: '$user->isAdmin() && $user->isActive()',
            children: [new TextNode('Content', 1, 0)],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (!($user->isAdmin() && $user->isActive())):', $result[0]->code);
    }

    public function testUnlessWithEmptyCondition(): void
    {
        $node = new DirectiveNode(
            name: 'unless',
            expression: 'empty($cart)',
            children: [new TextNode('Cart is not empty', 1, 0)],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node, $this->createContext());

        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (!(empty($cart))):', $result[0]->code);
    }

    public function testUnlessWithMultipleChildren(): void
    {
        $node = new DirectiveNode(
            name: 'unless',
            expression: '$hideContent',
            children: [
                new TextNode('First line', 1, 0),
                new TextNode('Second line', 2, 0),
            ],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node, $this->createContext());

        $this->assertCount(4, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]); // if
        $this->assertInstanceOf(TextNode::class, $result[1]); // first child
        $this->assertInstanceOf(TextNode::class, $result[2]); // second child
        $this->assertInstanceOf(RawPhpNode::class, $result[3]); // endif
    }
}
