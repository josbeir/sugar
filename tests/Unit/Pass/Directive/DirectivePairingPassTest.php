<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Directive;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\Node;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\AstPipeline;
use Sugar\Context\CompilationContext;
use Sugar\Directive\ForelseDirective;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\SwitchDirective;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\Directive\DirectivePairingPass;
use Sugar\Tests\Unit\Pass\MiddlewarePassTestCase;

final class DirectivePairingPassTest extends MiddlewarePassTestCase
{
    protected function getPass(): AstPassInterface
    {
        $registry = new DirectiveRegistry();
        $registry->register('forelse', ForelseDirective::class);
        $registry->register('empty', ForelseDirective::class);
        $registry->register('switch', SwitchDirective::class);
        $registry->register('case', SwitchDirective::class);

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
        // Switch doesn't implement PairedDirectiveInterface yet
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

    public function testDoesNotPairWhenDirectiveIsNotRegistered(): void
    {
        $primary = $this->directive('custom')
            ->expression('$items as $item')
            ->build();
        $pair = $this->directive('empty')->build();

        $registry = new DirectiveRegistry();
        $registry->register('forelse', ForelseDirective::class);

        $pass = new DirectivePairingPass($registry);

        $doc = $this->document()
            ->withChild($primary)
            ->withChild($pair)
            ->build();

        (new AstPipeline([$pass]))->execute($doc, $this->createTestContext());

        $this->assertNotInstanceOf(DirectiveNode::class, $primary->getPairedSibling());
        $this->assertFalse($pair->isConsumedByPairing());
    }

    public function testPairsDirectivesInsideComponent(): void
    {
        $forelse = $this->directive('forelse')
            ->expression('$items as $item')
            ->build();
        $empty = $this->directive('empty')->build();

        $component = $this->component(
            name: 'list',
            children: [$forelse, $empty],
            line: 1,
            column: 1,
        );

        $doc = $this->document()
            ->withChild($component)
            ->build();

        $this->execute($doc, $this->createTestContext());

        $this->assertSame($empty, $forelse->getPairedSibling());
        $this->assertTrue($empty->isConsumedByPairing());
    }

    public function testDoesNotPairWhenCompilerIsNotPairedInterface(): void
    {
        $primary = $this->directive('primary')->expression('$x')->build();
        $pair = $this->directive('pair')->build();

        $registry = new DirectiveRegistry();
        $registry->register('primary', new class implements DirectiveInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }
        });

        $pass = new DirectivePairingPass($registry);
        $doc = $this->document()
            ->withChild($primary)
            ->withChild($pair)
            ->build();

        (new AstPipeline([$pass]))->execute($doc, $this->createTestContext());

        $this->assertNotInstanceOf(DirectiveNode::class, $primary->getPairedSibling());
    }
}
