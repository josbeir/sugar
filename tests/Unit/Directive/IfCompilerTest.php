<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\IfCompiler;

final class IfCompilerTest extends TestCase
{
    private IfCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new IfCompiler();
    }

    public function testCompileIf(): void
    {
        $node = new DirectiveNode(
            name: 'if',
            expression: '$showContent',
            children: [new TextNode('Content', 1, 1)],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(3, $result); // if, content, endif
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('if ($showContent):', $result[0]->code);
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('endif;', $result[2]->code);
    }

    public function testCompileElseif(): void
    {
        $node = new DirectiveNode(
            name: 'elseif',
            expression: '$otherCondition',
            children: [new TextNode('Other', 1, 1)],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(2, $result); // elseif, content (no endif for elseif)
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('elseif ($otherCondition):', $result[0]->code);
    }

    public function testCompileElse(): void
    {
        $node = new DirectiveNode(
            name: 'else',
            expression: '',
            children: [new TextNode('Fallback', 1, 1)],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(2, $result); // else, content (no endif for else)
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('else:', $result[0]->code);
    }

    public function testCompileIfWithElseBranch(): void
    {
        $node = new DirectiveNode(
            name: 'if',
            expression: '$condition',
            children: [new TextNode('True', 1, 1)],
            elseChildren: [new TextNode('False', 2, 1)],
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(5, $result); // if, true content, else, false content, endif
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('if ($condition):', $result[0]->code);
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('else:', $result[2]->code);
        $this->assertInstanceOf(RawPhpNode::class, $result[4]);
        $this->assertStringContainsString('endif;', $result[4]->code);
    }
}
