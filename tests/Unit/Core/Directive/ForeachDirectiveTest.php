<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Directive\ForeachDirective;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Exception\SyntaxException;

final class ForeachDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new ForeachDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'foreach';
    }

    public function testCompileForeach(): void
    {
        $node = $this->directive('foreach')
            ->expression('$items as $item')
            ->withChild($this->text('Item'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(8)
            ->hasPhpCode('$__loopStack')
            ->hasPhpCode('LoopMetadata')
            ->hasPhpCode('foreach ($items as $item):')
            ->hasPhpCode('$loop->next()')
            ->hasPhpCode('endforeach;')
            ->hasPhpCode('array_pop($__loopStack)');
    }

    public function testCompileForeachWithKey(): void
    {
        $node = $this->directive('foreach')
            ->expression('$users as $id => $user')
            ->withChild($this->text('User'))
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(8)
            ->hasPhpCode('LoopMetadata($users')
            ->hasPhpCode('foreach ($users as $id => $user):');
    }

    public function testCompileForeachThrowsForMissingIterationExpression(): void
    {
        $node = $this->directive('foreach')
            ->expression('true')
            ->withChild($this->text('Item'))
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:foreach requires an expression like "$items as $item".');

        $this->directiveCompiler->compile($node, $this->createTestContext());
    }

    public function testCompileForeachRepeatsElementWithMultipleElementChildren(): void
    {
        $listItem = $this->element('li')
            ->withChildren([
                $this->element('h1')->withChild($this->text('Title'))->build(),
                $this->element('hr')->selfClosing()->build(),
            ])
            ->build();

        $node = $this->directive('foreach')
            ->expression('range(1, 3) as $x')
            ->withChild($listItem)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertCount(8, $result);
        $this->assertInstanceOf(ElementNode::class, $result[4]);
        $this->assertSame('li', $result[4]->tag);

        $this->assertAst($result)
            ->hasPhpCode('foreach (range(1, 3) as $x):')
            ->hasPhpCode('endforeach;');
    }

    public function testCompileForeachUsesWrapperModeForSingleElementChild(): void
    {
        $list = $this->element('ul')
            ->withChildren([
                $this->text("\n    "),
                $this->element('li')->withChild($this->text('Item'))->build(),
                $this->text("\n"),
            ])
            ->build();

        $node = $this->directive('foreach')
            ->expression('$items as $item')
            ->withChild($list)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertSame('ul', $result[0]->tag);

        $wrapperChildren = $result[0]->children;
        $this->assertNotEmpty($wrapperChildren);
        $this->assertContainsOnlyInstancesOf(Node::class, $wrapperChildren);

        $phpChildren = array_values(array_filter(
            $wrapperChildren,
            static fn(Node $child): bool => $child instanceof RawPhpNode,
        ));

        $this->assertNotEmpty($phpChildren);

        $containsForeach = false;

        foreach ($phpChildren as $phpChild) {
            if (str_contains($phpChild->code, 'foreach ($items as $item):')) {
                $containsForeach = true;

                break;
            }
        }

        $this->assertTrue($containsForeach);
    }
}
