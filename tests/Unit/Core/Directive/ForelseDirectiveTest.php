<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Directive\ForelseDirective;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Enum\DirectiveType;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Runtime\EmptyHelper;

final class ForelseDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new ForelseDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'forelse';
    }

    public function testGetType(): void
    {
        $this->assertSame(DirectiveType::CONTROL_FLOW, $this->directiveCompiler->getType());
    }

    public function testGetPairingDirective(): void
    {
        /** @var \Sugar\Core\Directive\ForelseDirective $compiler */
        $compiler = $this->directiveCompiler;
        $this->assertSame('empty', $compiler->getPairingDirective());
    }

    public function testCompileForelseWithoutEmpty(): void
    {
        $node = $this->directive('forelse')
            ->expression('$items as $item')
            ->withChild($this->text('Item'))
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(8)
            ->hasPhpCode('$__loopStack')
            ->hasPhpCode('LoopMetadata')
            ->hasPhpCode('$items')
            ->hasPhpCode('foreach ($items as $item):')
            ->hasPhpCode('$loop->next()')
            ->hasPhpCode('endforeach;')
            ->hasPhpCode('array_pop($__loopStack)');
    }

    public function testCompileForelseWithEmpty(): void
    {
        $emptyElement = $this->element('div')
            ->withChild($this->text('No items'))
            ->at(1, 1)
            ->build();

        $emptyNode = $this->directive('empty')
            ->expression('true')
            ->withChild($emptyElement)
            ->at(1, 1)
            ->build();

        $node = $this->directive('forelse')
            ->expression('$items as $item')
            ->withChild($this->text('Item'))
            ->at(1, 1)
            ->build();

        // Wire the pairing
        $node->setPairedSibling($emptyNode);

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        // Should contain: if, loopStack push, LoopMetadata, foreach, content, next(), endforeach, loopStack pop, else, elseChildren, endif
        $this->assertCount(12, $result);

        // Check opening if
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('if (!' . EmptyHelper::class . '::isEmpty($items)):', $result[0]->code);

        // Check for loop setup
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('$__loopStack', $result[2]->code);

        $this->assertInstanceOf(RawPhpNode::class, $result[3]);
        $this->assertStringContainsString('LoopMetadata', $result[3]->code);

        // Check foreach
        $this->assertInstanceOf(RawPhpNode::class, $result[4]);
        $this->assertStringContainsString('foreach ($items as $item):', $result[4]->code);

        // Check loop increment
        $this->assertInstanceOf(RawPhpNode::class, $result[6]);
        $this->assertStringContainsString('$loop->next()', $result[6]->code);

        // Check endforeach
        $this->assertInstanceOf(RawPhpNode::class, $result[7]);
        $this->assertStringContainsString('endforeach;', $result[7]->code);

        // Check loop restoration
        $this->assertInstanceOf(RawPhpNode::class, $result[8]);
        $this->assertStringContainsString('array_pop($__loopStack)', $result[8]->code);

        // Check else
        $this->assertInstanceOf(RawPhpNode::class, $result[9]);
        $this->assertStringContainsString('else:', $result[9]->code);

        // Check elseChildren element is included
        $this->assertInstanceOf(ElementNode::class, $result[10]);

        // Check endif
        $this->assertInstanceOf(RawPhpNode::class, $result[11]);
        $this->assertStringContainsString('endif;', $result[11]->code);
    }

    public function testCompileForelseWithKeyValue(): void
    {
        $node = $this->directive('forelse')
            ->expression('$users as $id => $user')
            ->withChild($this->text('User'))
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('LoopMetadata($users')
            ->hasPhpCode('foreach ($users as $id => $user):');
    }

    public function testCompileForelseWithComplexExpression(): void
    {
        $node = $this->directive('forelse')
            ->expression('range(1, 10) as $num')
            ->withChild($this->text('Number'))
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasPhpCode('LoopMetadata(range(1, 10)')
            ->hasPhpCode('foreach (range(1, 10) as $num):');
    }

    public function testCompileForelseThrowsForMissingIterationExpression(): void
    {
        $node = $this->directive('forelse')
            ->expression('true')
            ->withChild($this->text('Item'))
            ->at(1, 1)
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:forelse requires an expression like "$items as $item".');

        $this->directiveCompiler->compile($node, $this->createTestContext());
    }
}
