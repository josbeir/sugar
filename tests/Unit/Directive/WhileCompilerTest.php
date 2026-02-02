<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\WhileCompiler;

final class WhileCompilerTest extends TestCase
{
    private WhileCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new WhileCompiler();
    }

    public function testCompileWhile(): void
    {
        $node = new DirectiveNode(
            name: 'while',
            expression: '$counter < 10',
            children: [new TextNode('Loop', 1, 1)],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(3, $result); // while, content, endwhile
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('while ($counter < 10):', $result[0]->code);
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('endwhile;', $result[2]->code);
    }
}
