<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\ForeachCompiler;

final class ForeachCompilerTest extends TestCase
{
    private ForeachCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ForeachCompiler();
    }

    public function testCompileForeach(): void
    {
        $node = new DirectiveNode(
            name: 'foreach',
            expression: '$items as $item',
            children: [new TextNode('Item', 1, 1)],
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        // Should contain: loopStack push, LoopMetadata creation, foreach, content, next(), endforeach, loopStack pop
        $this->assertCount(8, $result);

        // Check for loop setup
        $this->assertInstanceOf(RawPhpNode::class, $result[1]);
        $this->assertStringContainsString('$__loopStack', $result[1]->code);

        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('LoopMetadata', $result[2]->code);

        // Check foreach
        $this->assertInstanceOf(RawPhpNode::class, $result[3]);
        $this->assertStringContainsString('foreach ($items as $item):', $result[3]->code);

        // Check loop increment
        $this->assertInstanceOf(RawPhpNode::class, $result[5]);
        $this->assertStringContainsString('$loop->next()', $result[5]->code);

        // Check endforeach
        $this->assertInstanceOf(RawPhpNode::class, $result[6]);
        $this->assertStringContainsString('endforeach;', $result[6]->code);

        // Check loop restoration
        $this->assertInstanceOf(RawPhpNode::class, $result[7]);
        $this->assertStringContainsString('array_pop($__loopStack)', $result[7]->code);
    }

    public function testCompileForeachWithKey(): void
    {
        $node = new DirectiveNode(
            name: 'foreach',
            expression: '$users as $id => $user',
            children: [new TextNode('User', 1, 1)],
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(8, $result);

        // Check LoopMetadata uses correct collection
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('LoopMetadata($users', $result[2]->code);

        // Check foreach expression preserved
        $this->assertInstanceOf(RawPhpNode::class, $result[3]);
        $this->assertStringContainsString('foreach ($users as $id => $user):', $result[3]->code);
    }
}
