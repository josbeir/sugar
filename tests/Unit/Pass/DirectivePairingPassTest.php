<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\TextNode;
use Sugar\Context\CompilationContext;
use Sugar\Directive\ForelseCompiler;
use Sugar\Directive\SwitchCompiler;
use Sugar\Extension\ExtensionRegistry;
use Sugar\Pass\Directive\DirectivePairingPass;

final class DirectivePairingPassTest extends TestCase
{
    private DirectivePairingPass $pass;

    private ExtensionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ExtensionRegistry();
        $this->registry->registerDirective('forelse', ForelseCompiler::class);
        $this->registry->registerDirective('empty', ForelseCompiler::class);
        $this->registry->registerDirective('switch', SwitchCompiler::class);
        $this->registry->registerDirective('case', SwitchCompiler::class);

        $this->pass = new DirectivePairingPass($this->registry);
    }

    public function testWiresParentReferences(): void
    {
        $child1 = new TextNode('Hello', 1, 1);
        $child2 = new TextNode('World', 1, 7);
        $doc = new DocumentNode([$child1, $child2]);

        $this->pass->execute($doc, $this->createContext());

        $this->assertSame($doc, $child1->getParent());
        $this->assertSame($doc, $child2->getParent());
    }

    public function testWiresNestedParentReferences(): void
    {
        $grandchild = new TextNode('Inner', 1, 1);
        $child = new DirectiveNode('if', '$x', [$grandchild], 1, 1);
        $doc = new DocumentNode([$child]);

        $this->pass->execute($doc, $this->createContext());

        $this->assertSame($doc, $child->getParent());
        $this->assertSame($child, $grandchild->getParent());
    }

    public function testPairsForelseWithEmpty(): void
    {
        $forelse = new DirectiveNode('forelse', '$items as $item', [], 1, 1);
        $empty = new DirectiveNode('empty', '', [], 2, 1);
        $doc = new DocumentNode([$forelse, $empty]);

        $this->pass->execute($doc, $this->createContext());

        $this->assertSame($empty, $forelse->getPairedSibling());
    }

    public function testDoesNotPairNonSiblingDirectives(): void
    {
        $forelse = new DirectiveNode('forelse', '$items as $item', [], 1, 1);
        $text = new TextNode('Some text', 2, 1);
        $empty = new DirectiveNode('empty', '', [], 3, 1);
        $doc = new DocumentNode([$forelse, $text, $empty]);

        $this->pass->execute($doc, $this->createContext());

        // Should still pair even with text node between them
        $this->assertSame($empty, $forelse->getPairedSibling());
    }

    public function testDoesNotPairForelseWithoutEmpty(): void
    {
        $forelse = new DirectiveNode('forelse', '$items as $item', [], 1, 1);
        $text = new TextNode('Text', 2, 1);
        $doc = new DocumentNode([$forelse, $text]);

        $this->pass->execute($doc, $this->createContext());

        $this->assertNull($forelse->getPairedSibling());
    }

    public function testDoesNotPairNonPairedDirectiveTypes(): void
    {
        // Switch doesn't implement PairedDirectiveCompilerInterface yet
        $switch = new DirectiveNode('switch', '$value', [], 1, 1);
        $case1 = new DirectiveNode('case', '1', [], 2, 1);
        $doc = new DocumentNode([$switch, $case1]);

        $this->pass->execute($doc, $this->createContext());

        // Should not pair since switch doesn't implement the interface
        $this->assertNull($switch->getPairedSibling());
    }

    protected function createContext(
        string $source = '',
        string $templatePath = 'test.sugar.php',
        bool $debug = false,
    ): CompilationContext {
        return new CompilationContext($templatePath, $source, $debug);
    }
}
