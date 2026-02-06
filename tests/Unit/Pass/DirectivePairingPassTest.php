<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Directive\ForelseCompiler;
use Sugar\Directive\SwitchCompiler;
use Sugar\Extension\ExtensionRegistry;
use Sugar\Pass\Directive\DirectivePairingPass;
use Sugar\Pass\PassInterface;

final class DirectivePairingPassTest extends PassTestCase
{
    protected function getPass(): PassInterface
    {
        $registry = new ExtensionRegistry();
        $registry->registerDirective('forelse', ForelseCompiler::class);
        $registry->registerDirective('empty', ForelseCompiler::class);
        $registry->registerDirective('switch', SwitchCompiler::class);
        $registry->registerDirective('case', SwitchCompiler::class);

        return new DirectivePairingPass($registry);
    }

    public function testWiresParentReferences(): void
    {
        $child1 = $this->createText('Hello');
        $child2 = $this->createText('World');
        $doc = new DocumentNode([$child1, $child2]);

        $this->execute($doc, $this->createTestContext());

        $this->assertSame($doc, $child1->getParent());
        $this->assertSame($doc, $child2->getParent());
    }

    public function testWiresNestedParentReferences(): void
    {
        $grandchild = $this->createText('Inner');
        $child = new DirectiveNode('if', '$x', [$grandchild], 1, 1);
        $doc = new DocumentNode([$child]);

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
        $switch = new DirectiveNode('switch', '$value', [], 1, 1);
        $case1 = new DirectiveNode('case', '1', [], 2, 1);
        $doc = new DocumentNode([$switch, $case1]);

        $this->execute($doc, $this->createTestContext());

        // Should not pair since switch doesn't implement the interface
        $this->assertNotInstanceOf(DirectiveNode::class, $switch->getPairedSibling());
    }
}
