<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\Pipeline\AstPipeline;
use Sugar\Extension\Component\Pass\SlotOutletPass;
use Sugar\Extension\Component\Runtime\SlotOutletHelper;
use Sugar\Tests\Helper\Trait\AstStringifyTrait;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Tests for SlotOutletPass.
 *
 * Verifies that elements and fragments with `s:slot` attributes are transformed
 * into conditional PHP blocks that delegate to SlotOutletHelper::render()
 * for element outlets and output slot content directly for fragment outlets.
 */
final class SlotOutletPassTest extends TestCase
{
    use AstStringifyTrait;
    use NodeBuildersTrait;
    use TemplateTestHelperTrait;

    private AstPipeline $pipeline;

    protected function setUp(): void
    {
        $pass = new SlotOutletPass('s:slot');
        $this->pipeline = new AstPipeline([$pass]);
    }

    private function executePipeline(DocumentNode $ast): DocumentNode
    {
        return $this->pipeline->execute($ast, $this->createContext());
    }

    // ================================================================
    // Element outlet transformation
    // ================================================================

    /**
     * Test that an element with s:slot is transformed into conditional PHP.
     */
    public function testTransformsElementOutletToConditionalBlock(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('h2')
                    ->attribute('s:slot', 'header')
                    ->attribute('class', 'title')
                    ->withChild($this->text('Default Header'))
                    ->build(),
            )
            ->build();

        $result = $this->executePipeline($ast);

        // Should produce: RawPhp(if), OutputNode(renderSlotOutlet), RawPhp(else), Element(fallback), RawPhp(endif)
        $this->assertCount(5, $result->children);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[2]);
        $this->assertInstanceOf(ElementNode::class, $result->children[3]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[4]);

        // Verify if condition
        $this->assertStringContainsString('$header', $result->children[0]->code);
        $this->assertStringContainsString('if', $result->children[0]->code);

        // Verify renderSlotOutlet call
        $outputNode = $result->children[1];
        $this->assertFalse($outputNode->escape);
        $this->assertStringContainsString(SlotOutletHelper::class . '::render', $outputNode->expression);
        $this->assertStringContainsString('$header', $outputNode->expression);
        $this->assertStringContainsString("'h2'", $outputNode->expression);
        $this->assertStringContainsString("'class' => 'title'", $outputNode->expression);

        // Verify fallback element has no s:slot attribute
        $fallback = $result->children[3];
        $this->assertSame('h2', $fallback->tag);
        $this->assertCount(1, $fallback->attributes);
        $this->assertSame('class', $fallback->attributes[0]->name);
    }

    /**
     * Test that element outlet without extra attributes generates empty attrs array.
     */
    public function testElementOutletWithoutAttributesGeneratesEmptyArray(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->attribute('s:slot', 'content')
                    ->withChild($this->text('Fallback'))
                    ->build(),
            )
            ->build();

        $result = $this->executePipeline($ast);

        $outputNode = $result->children[1];
        $this->assertInstanceOf(OutputNode::class, $outputNode);
        $this->assertStringContainsString('[]', $outputNode->expression);
    }

    /**
     * Test that s:slot without a value maps to the default slot.
     */
    public function testBooleanSlotAttributeMapsToDefaultSlot(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->attributeNode($this->attributeNode('s:slot', null))
                    ->withChild($this->text('Content'))
                    ->build(),
            )
            ->build();

        $result = $this->executePipeline($ast);

        $ifNode = $result->children[0];
        $this->assertInstanceOf(RawPhpNode::class, $ifNode);
        $this->assertStringContainsString('$slot', $ifNode->code);

        $outputNode = $result->children[1];
        $this->assertInstanceOf(OutputNode::class, $outputNode);
        $this->assertStringContainsString('$slot', $outputNode->expression);
    }

    /**
     * Test that self-closing element outlets are handled correctly.
     */
    public function testSelfClosingElementOutlet(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('hr')
                    ->attribute('s:slot', 'divider')
                    ->selfClosing()
                    ->build(),
            )
            ->build();

        $result = $this->executePipeline($ast);

        $this->assertCount(5, $result->children);
        $fallback = $result->children[3];
        $this->assertInstanceOf(ElementNode::class, $fallback);
        $this->assertTrue($fallback->selfClosing);
    }

    // ================================================================
    // Fragment outlet transformation
    // ================================================================

    /**
     * Test that a fragment with s:slot is transformed to conditional output.
     */
    public function testTransformsFragmentOutletToConditionalOutput(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->fragment(
                    attributes: [$this->attribute('s:slot', 'sidebar')],
                    children: [$this->text('Default sidebar')],
                ),
            )
            ->build();

        $result = $this->executePipeline($ast);

        // Should produce: RawPhp(if), OutputNode($sidebar), RawPhp(else), TextNode(fallback), RawPhp(endif)
        $this->assertCount(5, $result->children);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[2]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[4]);

        // Fragment outlet outputs the slot variable directly
        $outputNode = $result->children[1];
        $this->assertFalse($outputNode->escape);
        $this->assertSame('$sidebar', $outputNode->expression);

        // Fallback content
        $this->assertStringContainsString('Default sidebar', $this->astToString($result));
    }

    /**
     * Test that fragment outlet with multiple fallback children preserves all.
     */
    public function testFragmentOutletPreservesMultipleFallbackChildren(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->fragment(
                    attributes: [$this->attribute('s:slot', 'nav')],
                    children: [
                        $this->text('Link 1'),
                        $this->text(' | '),
                        $this->text('Link 2'),
                    ],
                ),
            )
            ->build();

        $result = $this->executePipeline($ast);

        // if, output, else, 3 text nodes, endif = 7
        $this->assertCount(7, $result->children);
    }

    // ================================================================
    // Elements without s:slot are not transformed
    // ================================================================

    /**
     * Test that elements without s:slot are left untouched.
     */
    public function testLeavesElementsWithoutSlotAttributeUntouched(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->attribute('class', 'container')
                    ->withChild($this->text('Content'))
                    ->build(),
            )
            ->build();

        $result = $this->executePipeline($ast);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertSame('div', $result->children[0]->tag);
    }

    /**
     * Test that fragments without s:slot are left untouched.
     */
    public function testLeavesFragmentsWithoutSlotAttributeUntouched(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->fragment(
                    children: [$this->text('Hello')],
                ),
            )
            ->build();

        $result = $this->executePipeline($ast);

        $this->assertCount(1, $result->children);
    }

    // ================================================================
    // Slot meta reference in generated code
    // ================================================================

    /**
     * Test that element outlet references __slot_meta for the correct slot.
     */
    public function testElementOutletReferencesSlotMeta(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('h3')
                    ->attribute('s:slot', 'title')
                    ->withChild($this->text('Default Title'))
                    ->build(),
            )
            ->build();

        $result = $this->executePipeline($ast);

        $outputNode = $result->children[1];
        $this->assertInstanceOf(OutputNode::class, $outputNode);
        $this->assertStringContainsString("\$__slot_meta['title']", $outputNode->expression);
    }

    /**
     * Test that multiple slot outlets in same document are each transformed independently.
     */
    public function testMultipleSlotOutletsTransformedIndependently(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->element('h2')
                    ->attribute('s:slot', 'header')
                    ->withChild($this->text('Header'))
                    ->build(),
                $this->text('Body content'),
                $this->element('footer')
                    ->attribute('s:slot', 'footer')
                    ->withChild($this->text('Footer'))
                    ->build(),
            ])
            ->build();

        $result = $this->executePipeline($ast);

        // header: 5 nodes, body: 1 text, footer: 5 nodes = 11
        $this->assertCount(11, $result->children);

        // Verify both slots are referenced
        $stringified = $this->astToString($result);
        $this->assertStringContainsString('$header', $stringified);
        $this->assertStringContainsString('$footer', $stringified);
    }
}
