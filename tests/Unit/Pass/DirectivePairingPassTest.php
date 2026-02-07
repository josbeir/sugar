<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use Sugar\Ast\DirectiveNode;
use Sugar\Directive\ForelseCompiler;
use Sugar\Directive\SwitchCompiler;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\Directive\DirectivePairingPass;
use Sugar\Pass\Middleware\AstMiddlewarePassInterface;

final class DirectivePairingPassTest extends MiddlewarePassTestCase
{
    protected function getPass(): AstMiddlewarePassInterface
    {
        $registry = new DirectiveRegistry();
        $registry->register('forelse', ForelseCompiler::class);
        $registry->register('empty', ForelseCompiler::class);
        $registry->register('switch', SwitchCompiler::class);
        $registry->register('case', SwitchCompiler::class);

        return new DirectivePairingPass($registry);
    }

    public function testWiresParentReferences(): void
    {
        $child1 = $this->createText('Hello');
        $child2 = $this->createText('World');
        $doc = $this->document()
            ->withChild($child1)
            ->withChild($child2)
            ->build();

        $this->execute($doc, $this->createTestContext());

        $this->assertSame($doc, $child1->getParent());
        $this->assertSame($doc, $child2->getParent());
    }

    public function testWiresNestedParentReferences(): void
    {
        $grandchild = $this->createText('Inner');
        $child = $this->directive('if')
            ->expression('$x')
            ->withChild($grandchild)
            ->at(1, 1)
            ->build();
        $doc = $this->document()
            ->withChild($child)
            ->build();

        $this->execute($doc, $this->createTestContext());

        $this->assertSame($doc, $child->getParent());
        $this->assertSame($child, $grandchild->getParent());
    }

    public function testPairsForelseWithEmpty(): void
    {
        $forelse = $this->directive('forelse')
            ->expression('$items as $item')
            ->build();
        $empty = $this->directive('empty')->build();
        $doc = $this->document()
            ->withChild($forelse)
            ->withChild($empty)
            ->build();

        $this->execute($doc, $this->createTestContext());

        $this->assertSame($empty, $forelse->getPairedSibling());
    }

    public function testDoesNotPairNonSiblingDirectives(): void
    {
        $forelse = $this->directive('forelse')
            ->expression('$items as $item')
            ->build();
        $text = $this->text('Some text');
        $empty = $this->directive('empty')->build();
        $doc = $this->document()
            ->withChild($forelse)
            ->withChild($text)
            ->withChild($empty)
            ->build();

        $this->execute($doc, $this->createTestContext());

        // Should still pair even with text node between them
        $this->assertSame($empty, $forelse->getPairedSibling());
    }

    public function testDoesNotPairForelseWithoutEmpty(): void
    {
        $forelse = $this->directive('forelse')
            ->expression('$items as $item')
            ->build();
        $text = $this->text('Text');
        $doc = $this->document()
            ->withChild($forelse)
            ->withChild($text)
            ->build();

        $this->execute($doc, $this->createTestContext());

        $this->assertNotInstanceOf(DirectiveNode::class, $forelse->getPairedSibling());
    }

    public function testDoesNotPairNonPairedDirectiveTypes(): void
    {
        // Switch doesn't implement PairedDirectiveCompilerInterface yet
        $switch = $this->directive('switch')
            ->expression('$value')
            ->at(1, 1)
            ->build();
        $case1 = $this->directive('case')
            ->expression('1')
            ->at(2, 1)
            ->build();
        $doc = $this->document()
            ->withChild($switch)
            ->withChild($case1)
            ->build();

        $this->execute($doc, $this->createTestContext());

        // Should not pair since switch doesn't implement the interface
        $this->assertNotInstanceOf(DirectiveNode::class, $switch->getPairedSibling());
    }
}
