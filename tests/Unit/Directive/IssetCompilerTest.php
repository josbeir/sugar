<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\IssetCompiler;

final class IssetCompilerTest extends TestCase
{
    private IssetCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new IssetCompiler();
    }

    public function testCompilesIssetDirective(): void
    {
        $node = new DirectiveNode(
            name: 'isset',
            expression: '$user',
            children: [new TextNode('User exists', 1, 0)],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(3, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (isset($user)):', $result[0]->code);
        $this->assertInstanceOf(TextNode::class, $result[1]);
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertSame('endif;', $result[2]->code);
    }

    public function testIssetWithMultipleVariables(): void
    {
        $node = new DirectiveNode(
            name: 'isset',
            expression: '$user, $profile',
            children: [new TextNode('Both exist', 1, 0)],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (isset($user, $profile)):', $result[0]->code);
    }

    public function testIssetWithArrayAccess(): void
    {
        $node = new DirectiveNode(
            name: 'isset',
            expression: '$data[\'key\']',
            children: [new TextNode('Key exists', 1, 0)],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (isset($data[\'key\'])):', $result[0]->code);
    }

    public function testIssetWithPropertyAccess(): void
    {
        $node = new DirectiveNode(
            name: 'isset',
            expression: '$user->email',
            children: [new TextNode('Email exists', 1, 0)],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertSame('if (isset($user->email)):', $result[0]->code);
    }

    public function testIssetWithMultipleChildren(): void
    {
        $node = new DirectiveNode(
            name: 'isset',
            expression: '$content',
            children: [
                new TextNode('First line', 1, 0),
                new TextNode('Second line', 2, 0),
            ],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(4, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]); // if
        $this->assertInstanceOf(TextNode::class, $result[1]); // first child
        $this->assertInstanceOf(TextNode::class, $result[2]); // second child
        $this->assertInstanceOf(RawPhpNode::class, $result[3]); // endif
    }
}
