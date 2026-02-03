<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\ForelseCompiler;
use Sugar\Enum\DirectiveType;

final class ForelseCompilerTest extends TestCase
{
    private ForelseCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ForelseCompiler();
    }

    public function testGetType(): void
    {
        $this->assertSame(DirectiveType::CONTROL_FLOW, $this->compiler->getType());
    }

    public function testGetPairingDirective(): void
    {
        $this->assertSame('empty', $this->compiler->getPairingDirective());
    }

    public function testCompileForelseWithoutEmpty(): void
    {
        $node = new DirectiveNode(
            name: 'forelse',
            expression: '$items as $item',
            children: [new TextNode('Item', 1, 1)],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        // Should contain: loopStack push, LoopMetadata creation, foreach, content, next(), endforeach, loopStack pop
        // No if/else wrapper when elseChildren is null
        $this->assertCount(7, $result);

        // Check for loop setup
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('$__loopStack', $result[0]->code);

        $this->assertInstanceOf(RawPhpNode::class, $result[1]);
        $this->assertStringContainsString('LoopMetadata', $result[1]->code);
        $this->assertStringContainsString('$items', $result[1]->code);

        // Check foreach
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('foreach ($items as $item):', $result[2]->code);

        // Check loop increment
        $this->assertInstanceOf(RawPhpNode::class, $result[4]);
        $this->assertStringContainsString('$loop->next()', $result[4]->code);

        // Check endforeach
        $this->assertInstanceOf(RawPhpNode::class, $result[5]);
        $this->assertStringContainsString('endforeach;', $result[5]->code);

        // Check loop restoration
        $this->assertInstanceOf(RawPhpNode::class, $result[6]);
        $this->assertStringContainsString('array_pop($__loopStack)', $result[6]->code);
    }

    public function testCompileForelseWithEmpty(): void
    {
        $emptyElement = new ElementNode(
            tag: 'div',
            attributes: [],
            children: [new TextNode('No items', 1, 1)],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $node = new DirectiveNode(
            name: 'forelse',
            expression: '$items as $item',
            children: [new TextNode('Item', 1, 1)],
            elseChildren: [$emptyElement],
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        // Should contain: if, loopStack push, LoopMetadata, foreach, content, next(), endforeach, loopStack pop, else, elseChildren, endif
        $this->assertCount(11, $result);

        // Check opening if
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('if (!empty($items)):', $result[0]->code);

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

        // Check else
        $this->assertInstanceOf(RawPhpNode::class, $result[8]);
        $this->assertStringContainsString('else:', $result[8]->code);

        // Check elseChildren element is included
        $this->assertSame($emptyElement, $result[9]);

        // Check endif
        $this->assertInstanceOf(RawPhpNode::class, $result[10]);
        $this->assertStringContainsString('endif;', $result[10]->code);
    }

    public function testCompileForelseWithKeyValue(): void
    {
        $node = new DirectiveNode(
            name: 'forelse',
            expression: '$users as $id => $user',
            children: [new TextNode('User', 1, 1)],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        // Check LoopMetadata uses correct collection
        $this->assertInstanceOf(RawPhpNode::class, $result[1]);
        $this->assertStringContainsString('LoopMetadata($users', $result[1]->code);

        // Check foreach expression preserved
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('foreach ($users as $id => $user):', $result[2]->code);
    }

    public function testCompileForelseWithComplexExpression(): void
    {
        $node = new DirectiveNode(
            name: 'forelse',
            expression: 'range(1, 10) as $num',
            children: [new TextNode('Number', 1, 1)],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $result = $this->compiler->compile($node);

        // Check LoopMetadata uses correct collection (range(1, 10))
        $this->assertInstanceOf(RawPhpNode::class, $result[1]);
        $this->assertStringContainsString('LoopMetadata(range(1, 10)', $result[1]->code);

        // Check foreach expression preserved
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('foreach (range(1, 10) as $num):', $result[2]->code);
    }
}
