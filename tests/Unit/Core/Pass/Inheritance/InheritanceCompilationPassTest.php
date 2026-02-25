<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Pass\Inheritance;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\PhpImportNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Pass\Inheritance\InheritanceCompilationPass;
use Sugar\Tests\Unit\Core\Pass\MiddlewarePassTestCase;

/**
 * Unit tests for InheritanceCompilationPass.
 *
 * Verifies that inheritance-related Sugar directives (s:extends, s:block,
 * s:include, s:parent, s:append, s:prepend) are correctly transformed into
 * runtime PHP calls (renderExtends, defineBlock, renderBlock, renderInclude,
 * renderParent).
 *
 * These tests construct AST nodes directly to isolate the pass behavior
 * from the parser and other compilation passes.
 */
final class InheritanceCompilationPassTest extends MiddlewarePassTestCase
{
    protected function setUp(): void
    {
        $this->setUpCompilerWithStringLoader([
            'layout.sugar.php' => '<html><body><main s:block="content">Default</main></body></html>',
            'parent.sugar.php' => '<main s:block="content">Default</main>',
            'partial.sugar.php' => '<div>Partial</div>',
        ]);
        $this->pass = $this->getPass();
    }

    protected function getPass(): AstPassInterface
    {
        return new InheritanceCompilationPass(
            new SugarConfig(),
            $this->templateLoader,
        );
    }

    /**
     * Test that s:extends transforms into defineBlock + renderExtends runtime calls.
     */
    public function testExtendsTransformsIntoRuntimeCalls(): void
    {
        $ast = $this->buildExtendsDocument(
            parentPath: 'parent.sugar.php',
            blocks: [
                ['name' => 'content', 'mode' => 's:block', 'content' => 'Child content'],
            ],
        );

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->isNotEmpty()
            ->hasPhpCode('requireService')
            ->hasPhpCode('defineBlock')
            ->hasPhpCode('renderExtends');

        $this->assertPhpCodeContains($result, "renderExtends('@app/parent.sugar.php'");
        $this->assertPhpCodeContains($result, "defineBlock('content'");
    }

    /**
     * Test that blocks in extends documents emit replace-mode defineBlock closures.
     */
    public function testExtendsBlockReplaceModeEmitsCorrectCode(): void
    {
        $ast = $this->buildExtendsDocument(
            parentPath: 'parent.sugar.php',
            blocks: [
                ['name' => 'content', 'mode' => 's:block', 'content' => 'Replaced'],
            ],
        );

        $result = $this->execute($ast, $this->createTestContext());

        // Replace mode: defineBlock with ob_start/ob_get_clean, no renderParent
        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('defineBlock', $code);
        $this->assertStringContainsString('ob_start()', $code);
        $this->assertStringContainsString('ob_get_clean()', $code);
        $this->assertStringContainsString('Replaced', $code);
    }

    /**
     * Test that s:append mode wraps content with renderParent first (parent before child).
     */
    public function testExtendsBlockAppendModeEmitsParentBeforeChild(): void
    {
        $ast = $this->buildExtendsDocument(
            parentPath: 'parent.sugar.php',
            blocks: [
                ['name' => 'content', 'mode' => 's:append', 'content' => 'Appended'],
            ],
        );

        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('defineBlock', $code);
        // In append mode, renderParent comes before the appended content
        $renderParentPos = strpos($code, 'renderParent');
        $appendedPos = strpos($code, 'Appended');
        $this->assertNotFalse($renderParentPos, 'renderParent not found in output');
        $this->assertNotFalse($appendedPos, 'Appended content not found in output');
        $this->assertLessThan($appendedPos, $renderParentPos, 'renderParent should come before appended content');
    }

    /**
     * Test that s:prepend mode wraps content with renderParent after child (child before parent).
     */
    public function testExtendsBlockPrependModeEmitsChildBeforeParent(): void
    {
        $ast = $this->buildExtendsDocument(
            parentPath: 'parent.sugar.php',
            blocks: [
                ['name' => 'content', 'mode' => 's:prepend', 'content' => 'Prepended'],
            ],
        );

        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('defineBlock', $code);
        // In prepend mode, the prepended content comes before renderParent
        $renderParentPos = strpos($code, 'renderParent');
        $prependedPos = strpos($code, 'Prepended');
        $this->assertNotFalse($renderParentPos, 'renderParent not found in output');
        $this->assertNotFalse($prependedPos, 'Prepended content not found in output');
        $this->assertLessThan($renderParentPos, $prependedPos, 'Prepended content should come before renderParent');
    }

    /**
     * Test that s:block in a layout (non-extends) document emits renderBlock().
     */
    public function testLayoutBlockEmitsRenderBlock(): void
    {
        $blockElement = $this->element('main')
            ->attribute('s:block', 'content')
            ->withChild($this->text('Default content'))
            ->build();

        $ast = $this->document()->withChild($blockElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->isNotEmpty()
            ->containsElement('main');

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('renderBlock', $code);
        $this->assertStringContainsString("'content'", $code);
        $this->assertStringContainsString('get_defined_vars()', $code);
    }

    /**
     * Test that layout blocks emit $__tpl initialization.
     */
    public function testLayoutBlockEmitsTplInitialization(): void
    {
        $blockElement = $this->element('div')
            ->attribute('s:block', 'sidebar')
            ->withChild($this->text('Sidebar default'))
            ->build();

        $ast = $this->document()->withChild($blockElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('$__tpl = $__tpl ??', $code);
        $this->assertStringContainsString('requireService', $code);
    }

    /**
     * Test that s:block on a fragment outputs renderBlock without wrapper element.
     */
    public function testLayoutBlockOnFragmentEmitsRenderBlockWithoutWrapper(): void
    {
        $fragmentBlock = $this->fragment(
            attributes: [
                $this->attribute('s:block', 'content'),
            ],
            children: [
                $this->text('Fragment default'),
            ],
        );

        $ast = $this->document()->withChild($fragmentBlock)->build();
        $result = $this->execute($ast, $this->createTestContext());

        // Should NOT have a wrapper element, just RawPhpNode(s)
        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('renderBlock', $code);
        $this->assertStringContainsString("'content'", $code);
    }

    /**
     * Test that s:include transforms into renderInclude() with get_defined_vars().
     */
    public function testIncludeTransformsIntoRenderInclude(): void
    {
        $includeElement = $this->element('div')
            ->attribute('s:include', 'partial.sugar.php')
            ->build();

        $ast = $this->document()->withChild($includeElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->containsElement('div');

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('renderInclude', $code);
        $this->assertStringContainsString('get_defined_vars()', $code);
        $this->assertStringContainsString("'@app/partial.sugar.php'", $code);
    }

    /**
     * Test that s:include with s:with uses explicit variable expression.
     */
    public function testIncludeWithWithUsesExplicitVars(): void
    {
        $includeElement = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('s:include', AttributeValue::static('partial.sugar.php'), 1, 0),
                new AttributeNode('s:with', AttributeValue::static("['foo' => 'bar']"), 1, 0),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = $this->document()->withChild($includeElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('renderInclude', $code);
        $this->assertStringContainsString("['foo' => 'bar']", $code);
        // Should NOT use get_defined_vars() when s:with is provided
        $this->assertStringNotContainsString('get_defined_vars()', $code);
    }

    /**
     * Test that s:include on a fragment outputs renderInclude without wrapper.
     */
    public function testIncludeOnFragmentEmitsWithoutWrapper(): void
    {
        $includeFragment = $this->fragment(
            attributes: [
                $this->attribute('s:include', 'partial.sugar.php'),
            ],
        );

        $ast = $this->document()->withChild($includeFragment)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('renderInclude', $code);
        $this->assertStringContainsString("'@app/partial.sugar.php'", $code);
    }

    /**
     * Test that s:parent inside an extends block emits renderParent().
     */
    public function testParentPlaceholderInBlockEmitsRenderParent(): void
    {
        // Build extends doc with a block containing s:parent
        $parentPlaceholder = $this->fragment(
            attributes: [
                $this->attribute('s:parent', ''),
            ],
        );

        $blockFragment = $this->fragment(
            attributes: [
                $this->attribute('s:block', 'content'),
            ],
            children: [
                $this->text('Before parent'),
                $parentPlaceholder,
                $this->text('After parent'),
            ],
        );

        $extendsFragment = $this->fragment(
            attributes: [
                $this->attribute('s:extends', 'parent.sugar.php'),
            ],
        );

        $ast = $this->document()
            ->withChild($extendsFragment)
            ->withChild($blockFragment)
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('renderParent', $code);
        $this->assertStringContainsString("'content'", $code);
    }

    /**
     * Test that multiple blocks in an extends document all get defineBlock calls.
     */
    public function testExtendsWithMultipleBlocksEmitsMultipleDefineBlocks(): void
    {
        $ast = $this->buildExtendsDocument(
            parentPath: 'parent.sugar.php',
            blocks: [
                ['name' => 'header', 'mode' => 's:block', 'content' => 'Custom Header'],
                ['name' => 'content', 'mode' => 's:block', 'content' => 'Custom Content'],
                ['name' => 'footer', 'mode' => 's:block', 'content' => 'Custom Footer'],
            ],
        );

        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString("defineBlock('header'", $code);
        $this->assertStringContainsString("defineBlock('content'", $code);
        $this->assertStringContainsString("defineBlock('footer'", $code);
        $this->assertStringContainsString('Custom Header', $code);
        $this->assertStringContainsString('Custom Content', $code);
        $this->assertStringContainsString('Custom Footer', $code);
    }

    /**
     * Test that inheritance attributes are removed from output elements.
     */
    public function testInheritanceAttributesRemovedFromOutputElements(): void
    {
        $blockElement = $this->element('main')
            ->attribute('s:block', 'content')
            ->attribute('class', 'container')
            ->withChild($this->text('Content'))
            ->build();

        $ast = $this->document()->withChild($blockElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        // The <main> should still exist but s:block attribute should be removed
        $mainElement = $this->findElement('main', $result);
        $this->assertInstanceOf(ElementNode::class, $mainElement, 'main element should exist');

        // Check that s:block attribute was removed
        foreach ($mainElement->attributes as $attr) {
            $this->assertNotSame('s:block', $attr->name, 's:block attribute should be removed');
        }
    }

    /**
     * Test that s:include preserves non-inheritance attributes on the wrapper element.
     */
    public function testIncludePreservesNonInheritanceAttributes(): void
    {
        $includeElement = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('class', AttributeValue::static('wrapper'), 1, 0),
                new AttributeNode('s:include', AttributeValue::static('partial.sugar.php'), 1, 0),
                new AttributeNode('id', AttributeValue::static('my-div'), 1, 0),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = $this->document()->withChild($includeElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $divElement = $this->findElement('div', $result);
        $this->assertInstanceOf(ElementNode::class, $divElement, 'div element should exist');

        $attrNames = array_map(fn(AttributeNode $a) => $a->name, $divElement->attributes);
        $this->assertContains('class', $attrNames);
        $this->assertContains('id', $attrNames);
        $this->assertNotContains('s:include', $attrNames);
    }

    /**
     * Test that blocks filter skips extends and emits only matching blocks.
     */
    public function testBlocksFilterEmitsOnlyMatchingBlocks(): void
    {
        $ast = $this->buildExtendsDocument(
            parentPath: 'parent.sugar.php',
            blocks: [
                ['name' => 'header', 'mode' => 's:block', 'content' => 'Header Content'],
                ['name' => 'content', 'mode' => 's:block', 'content' => 'Body Content'],
                ['name' => 'footer', 'mode' => 's:block', 'content' => 'Footer Content'],
            ],
        );

        $context = $this->createTestContext(blocks: ['content']);
        $result = $this->execute($ast, $context);

        // With blocks filter, extends is skipped, only matching block content is output
        $code = $this->collectAllPhpCode($result);
        // Should NOT have renderExtends (extends is skipped)
        $this->assertStringNotContainsString('renderExtends', $code);
        // Matching block content should be present
        $this->assertDocumentContainsText($result, 'Body Content');
    }

    /**
     * Test that blocks filter in layout context removes non-matching blocks.
     */
    public function testBlocksFilterInLayoutRemovesNonMatchingBlocks(): void
    {
        $block1 = $this->element('header')
            ->attribute('s:block', 'header')
            ->withChild($this->text('Header'))
            ->build();

        $block2 = $this->element('main')
            ->attribute('s:block', 'content')
            ->withChild($this->text('Content'))
            ->build();

        $ast = $this->document()
            ->withChild($block1)
            ->withChild($block2)
            ->build();

        $context = $this->createTestContext(blocks: ['content']);
        $result = $this->execute($ast, $context);

        // Only content block should remain, header should be removed
        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString("'content'", $code);
        $this->assertStringNotContainsString("'header'", $code);
    }

    /**
     * Test that duplicate block definitions in extends child throw SyntaxException.
     */
    public function testDuplicateBlockDefinitionsThrowSyntaxException(): void
    {
        $ast = $this->buildExtendsDocument(
            parentPath: 'parent.sugar.php',
            blocks: [
                ['name' => 'content', 'mode' => 's:block', 'content' => 'First'],
                ['name' => 'content', 'mode' => 's:append', 'content' => 'Duplicate'],
            ],
        );

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Block "content" is defined multiple times');

        $this->execute($ast, $this->createTestContext());
    }

    /**
     * Test that s:parent outside a block context throws SyntaxException.
     */
    public function testParentOutsideBlockThrowsSyntaxException(): void
    {
        $parentPlaceholder = $this->fragment(
            attributes: [
                $this->attribute('s:parent', ''),
            ],
        );

        $extendsFragment = $this->fragment(
            attributes: [
                $this->attribute('s:extends', 'parent.sugar.php'),
            ],
        );

        $ast = $this->document()
            ->withChild($extendsFragment)
            ->withChild($parentPlaceholder)
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:parent is only allowed inside s:block');

        $this->execute($ast, $this->createTestContext());
    }

    /**
     * Test that s:parent on a non-fragment element throws SyntaxException.
     */
    public function testParentOnElementThrowsSyntaxException(): void
    {
        $parentElement = $this->element('div')
            ->attribute('s:parent', '')
            ->build();

        $blockFragment = $this->fragment(
            attributes: [
                $this->attribute('s:block', 'content'),
            ],
            children: [$parentElement],
        );

        $extendsFragment = $this->fragment(
            attributes: [
                $this->attribute('s:extends', 'parent.sugar.php'),
            ],
        );

        $ast = $this->document()
            ->withChild($extendsFragment)
            ->withChild($blockFragment)
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:parent must be used on <s-template>');

        $this->execute($ast, $this->createTestContext());
    }

    /**
     * Test that s:parent with extra attributes throws SyntaxException.
     */
    public function testParentWithExtraAttributesThrowsSyntaxException(): void
    {
        $parentFragment = $this->fragment(
            attributes: [
                $this->attribute('s:parent', ''),
                $this->attribute('class', 'extra'),
            ],
        );

        $blockFragment = $this->fragment(
            attributes: [
                $this->attribute('s:block', 'content'),
            ],
            children: [$parentFragment],
        );

        $extendsFragment = $this->fragment(
            attributes: [
                $this->attribute('s:extends', 'parent.sugar.php'),
            ],
        );

        $ast = $this->document()
            ->withChild($extendsFragment)
            ->withChild($blockFragment)
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:parent cannot be combined with other attributes');

        $this->execute($ast, $this->createTestContext());
    }

    /**
     * Test that s:parent with child content throws SyntaxException.
     */
    public function testParentWithChildContentThrowsSyntaxException(): void
    {
        $parentFragment = $this->fragment(
            attributes: [
                $this->attribute('s:parent', ''),
            ],
            children: [
                $this->text('Not allowed'),
            ],
        );

        $blockFragment = $this->fragment(
            attributes: [
                $this->attribute('s:block', 'content'),
            ],
            children: [$parentFragment],
        );

        $extendsFragment = $this->fragment(
            attributes: [
                $this->attribute('s:extends', 'parent.sugar.php'),
            ],
        );

        $ast = $this->document()
            ->withChild($extendsFragment)
            ->withChild($blockFragment)
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:parent cannot have child content');

        $this->execute($ast, $this->createTestContext());
    }

    /**
     * Test that PhpImportNode inside blocks is hoisted to document level.
     */
    public function testImportNodesAreHoistedFromBlocks(): void
    {
        $importNode = new PhpImportNode('use App\Helper\Formatter;', 1, 0);

        $blockFragment = $this->fragment(
            attributes: [
                $this->attribute('s:block', 'content'),
            ],
            children: [
                $importNode,
                $this->text('Content with import'),
            ],
        );

        $extendsFragment = $this->fragment(
            attributes: [
                $this->attribute('s:extends', 'parent.sugar.php'),
            ],
        );

        $ast = $this->document()
            ->withChild($extendsFragment)
            ->withChild($blockFragment)
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        // The import should appear at document level, not inside the closure
        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('use App\Helper\Formatter;', $code);
    }

    /**
     * Test that dependency tracking records resolved template paths.
     */
    public function testDependencyTrackingRecordsResolvedPaths(): void
    {
        $includeElement = $this->element('div')
            ->attribute('s:include', 'partial.sugar.php')
            ->build();

        $ast = $this->document()->withChild($includeElement)->build();

        $tracker = new DependencyTracker();
        $context = $this->createTestContext(tracker: $tracker);
        $this->execute($ast, $context);

        // StringTemplateLoader.sourcePath() returns null, so no file dependency is
        // actually recorded. But the resolve() call should still succeed without error.
        // Verify via getMetadata — dependencies array should be empty.
        $metadata = $tracker->getMetadata('test.sugar.php');
        $this->assertSame([], $metadata->dependencies);
    }

    /**
     * Test that extends resolves template paths relative to the current template.
     */
    public function testExtendsResolvesRelativePaths(): void
    {
        $ast = $this->buildExtendsDocument(
            parentPath: 'layout.sugar.php',
            blocks: [],
        );

        $context = $this->createTestContext(templatePath: 'pages/child.sugar.php');
        $result = $this->execute($ast, $context);

        $code = $this->collectAllPhpCode($result);
        // Should resolve relative to the referrer's directory
        $this->assertStringContainsString("renderExtends('@app/pages/layout.sugar.php'", $code);
    }

    /**
     * Test that a document without any inheritance attributes passes through unchanged.
     */
    public function testNonInheritanceDocumentPassesThroughUnchanged(): void
    {
        $element = $this->element('div')
            ->attribute('class', 'normal')
            ->withChild($this->text('Plain content'))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        // Should be unchanged
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);

        $div = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $div);
        $this->assertSame('div', $div->tag);
    }

    /**
     * Test that nodeToString serializes TextNode correctly inside block closures.
     */
    public function testBlockClosureContainsSerializedTextContent(): void
    {
        $blockElement = $this->element('section')
            ->attribute('s:block', 'main')
            ->withChild($this->text('Hello World'))
            ->build();

        $ast = $this->document()->withChild($blockElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('Hello World', $code);
    }

    /**
     * Test that nodeToString serializes RawPhpNode inside block closures.
     */
    public function testBlockClosureContainsSerializedPhpCode(): void
    {
        $phpNode = $this->rawPhp('echo "dynamic"; ');

        $blockElement = $this->element('section')
            ->attribute('s:block', 'main')
            ->withChild($phpNode)
            ->build();

        $ast = $this->document()->withChild($blockElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('echo "dynamic"', $code);
    }

    /**
     * Test that nodeToString serializes OutputNode with escaping inside block closures.
     */
    public function testBlockClosureContainsSerializedEscapedOutput(): void
    {
        $outputNode = $this->outputNode('$title', true, OutputContext::HTML);

        $blockElement = $this->element('section')
            ->attribute('s:block', 'main')
            ->withChild($outputNode)
            ->build();

        $ast = $this->document()->withChild($blockElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('__SugarEscaper::html($title)', $code);
    }

    /**
     * Test that nodeToString serializes nested ElementNode correctly.
     */
    public function testBlockClosureContainsSerializedNestedElements(): void
    {
        $inner = $this->element('span')
            ->attribute('class', 'inner')
            ->withChild($this->text('Nested'))
            ->build();

        $blockElement = $this->element('section')
            ->attribute('s:block', 'main')
            ->withChild($inner)
            ->build();

        $ast = $this->document()->withChild($blockElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('<span class="inner">', $code);
        $this->assertStringContainsString('Nested', $code);
        $this->assertStringContainsString('</span>', $code);
    }

    /**
     * Test that nodeToString serializes self-closing elements.
     */
    public function testBlockClosureContainsSerializedSelfClosingElement(): void
    {
        $selfClosing = $this->element('br')->selfClosing()->build();

        $blockElement = $this->element('section')
            ->attribute('s:block', 'main')
            ->withChild($selfClosing)
            ->build();

        $ast = $this->document()->withChild($blockElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString('<br />', $code);
    }

    /**
     * Test extends with blocks defined as children of the extends element.
     */
    public function testExtendsWithBlocksInsideExtendsElement(): void
    {
        $blockFragment = $this->fragment(
            attributes: [
                $this->attribute('s:block', 'content'),
            ],
            children: [
                $this->text('Inner block'),
            ],
        );

        $extendsElement = $this->element('main')
            ->attribute('s:extends', 'parent.sugar.php')
            ->withChild($blockFragment)
            ->build();

        $ast = $this->document()->withChild($extendsElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString("defineBlock('content'", $code);
        $this->assertStringContainsString('Inner block', $code);
        $this->assertStringContainsString('renderExtends', $code);
    }

    /**
     * Test that the $__tpl initialization uses conditional assignment in include context.
     */
    public function testIncludeInitializesTplWithConditionalAssignment(): void
    {
        $includeElement = $this->element('div')
            ->attribute('s:include', 'partial.sugar.php')
            ->build();

        $ast = $this->document()->withChild($includeElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        // Include uses ??= style init (not bare =)
        $this->assertStringContainsString('$__tpl = $__tpl ??', $code);
    }

    /**
     * Test that extends uses direct assignment for $__tpl initialization.
     */
    public function testExtendsInitializesTplWithDirectAssignment(): void
    {
        $ast = $this->buildExtendsDocument(
            parentPath: 'parent.sugar.php',
            blocks: [
                ['name' => 'content', 'mode' => 's:block', 'content' => 'Child'],
            ],
        );

        $result = $this->execute($ast, $this->createTestContext());

        // Find the first RawPhpNode (should be the init)
        $firstPhp = null;
        foreach ($result->children as $child) {
            if ($child instanceof RawPhpNode) {
                $firstPhp = $child;
                break;
            }
        }

        $this->assertInstanceOf(RawPhpNode::class, $firstPhp, 'Should have a RawPhpNode for init');
        $this->assertStringContainsString(
            '$__tpl = __SugarRuntimeEnvironment::requireService(__SugarTemplateRenderer::class);',
            $firstPhp->code,
        );
        // Extends uses direct assignment (not conditional ??=)
        $this->assertStringNotContainsString('$__tpl = $__tpl ??', $firstPhp->code);
    }

    /**
     * Test that sanitizeBlockVarName produces valid PHP variable names.
     */
    public function testBlockNameWithSpecialCharsIsSanitizedForVarName(): void
    {
        $ast = $this->buildExtendsDocument(
            parentPath: 'parent.sugar.php',
            blocks: [
                ['name' => 'header-nav', 'mode' => 's:block', 'content' => 'Navigation'],
            ],
        );

        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        // Hyphen should be replaced with underscore in variable name
        $this->assertStringContainsString('$__parentDefault_header_nav', $code);
    }

    /**
     * Test that nested s:extends inside descendants throws a syntax error.
     */
    public function testNestedExtendsInsideDescendantsThrowsSyntaxException(): void
    {
        $rootExtends = $this->fragment(
            attributes: [$this->attribute('s:extends', 'parent.sugar.php')],
        );

        $nestedExtends = $this->fragment(
            attributes: [$this->attribute('s:extends', 'parent.sugar.php')],
        );

        $container = $this->element('section')
            ->withChild($this->element('div')->withChild($nestedExtends)->build())
            ->build();

        $ast = $this->document()->withChildren([$rootExtends, $container])->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:extends is only allowed on root-level template elements');

        $this->execute($ast, $this->createTestContext());
    }

    /**
     * Test that s:parent placeholder with whitespace children is accepted.
     */
    public function testParentPlaceholderWithWhitespaceChildrenIsAllowed(): void
    {
        $parentPlaceholder = $this->fragment(
            attributes: [$this->attribute('s:parent', '')],
            children: [$this->text("  \n\t")],
        );

        $blockFragment = $this->fragment(
            attributes: [$this->attribute('s:block', 'content')],
            children: [$parentPlaceholder],
        );

        $extendsFragment = $this->fragment(
            attributes: [$this->attribute('s:extends', 'parent.sugar.php')],
        );

        $ast = $this->document()
            ->withChild($extendsFragment)
            ->withChild($blockFragment)
            ->build();

        $result = $this->execute($ast, $this->createTestContext());
        $code = $this->collectAllPhpCode($result);

        $this->assertStringContainsString('renderParent', $code);
    }

    /**
     * Test that non-block children inside extends element are ignored.
     */
    public function testExtendsIgnoresNonBlockChildrenInsideExtendsElement(): void
    {
        $extendsElement = $this->element('main')
            ->attribute('s:extends', 'parent.sugar.php')
            ->withChild($this->element('aside')->withChild($this->text('Ignored'))->build())
            ->withChild($this->fragment(
                attributes: [$this->attribute('s:block', 'content')],
                children: [$this->text('Inline block')],
            ))
            ->build();

        $ast = $this->document()->withChild($extendsElement)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $code = $this->collectAllPhpCode($result);
        $this->assertStringContainsString("defineBlock('content'", $code);
        $this->assertStringContainsString('Inline block', $code);
        $this->assertStringNotContainsString("defineBlock('aside'", $code);
    }

    /**
     * Test that directive children inside blocks are serialized from their children.
     */
    public function testBlockSerializationIncludesDirectiveChildren(): void
    {
        $directive = new DirectiveNode(
            name: 'if',
            expression: '$show',
            children: [$this->text('From directive child')],
            line: 1,
            column: 1,
        );

        $blockFragment = $this->fragment(
            attributes: [$this->attribute('s:block', 'content')],
            children: [$directive],
        );

        $ast = $this->document()
            ->withChild($this->fragment(attributes: [$this->attribute('s:extends', 'parent.sugar.php')]))
            ->withChild($blockFragment)
            ->build();

        $result = $this->execute($ast, $this->createTestContext());
        $code = $this->collectAllPhpCode($result);

        $this->assertStringContainsString('From directive child', $code);
    }

    /**
     * Test that dynamic block attributes serialize escaped and raw output correctly.
     */
    public function testBlockAttributeSerializationSupportsBooleanAndDynamicOutputs(): void
    {
        $contentElement = $this->element('div')
            ->attributeNode(new AttributeNode('disabled', AttributeValue::boolean(), 1, 0))
            ->attributeNode(new AttributeNode(
                'title',
                AttributeValue::parts([
                    'prefix-',
                    $this->outputNode('$safe', true, OutputContext::HTML_ATTRIBUTE),
                    '-suffix',
                ]),
                1,
                0,
            ))
            ->attributeNode(new AttributeNode(
                'data-raw',
                AttributeValue::parts([
                    $this->outputNode('$raw', false, OutputContext::RAW),
                ]),
                1,
                0,
            ))
            ->build();

        $blockFragment = $this->fragment(
            attributes: [$this->attribute('s:block', 'content')],
            children: [$contentElement],
        );

        $extendsFragment = $this->fragment(
            attributes: [$this->attribute('s:extends', 'parent.sugar.php')],
        );

        $ast = $this->document()->withChildren([$extendsFragment, $blockFragment])->build();
        $result = $this->execute($ast, $this->createTestContext());
        $code = $this->collectAllPhpCode($result);

        $this->assertStringContainsString(' disabled', $code);
        $this->assertStringContainsString('__SugarEscaper::attr($safe)', $code);
        $this->assertStringContainsString('<?php echo $raw; ?>', $code);
    }

    /**
     * Build an extends document with the given parent path and block definitions.
     *
     * @param string $parentPath The parent template path
     * @param array<array{name: string, mode: string, content: string}> $blocks Block definitions
     * @return \Sugar\Core\Ast\DocumentNode The constructed document AST
     */
    protected function buildExtendsDocument(string $parentPath, array $blocks): DocumentNode
    {
        $extendsFragment = $this->fragment(
            attributes: [
                $this->attribute('s:extends', $parentPath),
            ],
        );

        $children = [$extendsFragment];

        foreach ($blocks as $block) {
            $blockFragment = $this->fragment(
                attributes: [
                    $this->attribute($block['mode'], $block['name']),
                ],
                children: [
                    $this->text($block['content']),
                ],
            );
            $children[] = $blockFragment;
        }

        return new DocumentNode($children);
    }

    /**
     * Collect all PHP code from RawPhpNodes in a document (concatenated).
     *
     * @param \Sugar\Core\Ast\DocumentNode $document The document to inspect
     * @return string All RawPhpNode code concatenated
     */
    protected function collectAllPhpCode(DocumentNode $document): string
    {
        $code = '';
        foreach ($document->children as $child) {
            $code .= $this->collectPhpCodeRecursive($child);
        }

        return $code;
    }

    /**
     * Recursively collect PHP code from a node and its children.
     *
     * @param \Sugar\Core\Ast\Node $node The node to inspect
     * @return string All RawPhpNode code found
     */
    private function collectPhpCodeRecursive(Node $node): string
    {
        $code = '';

        if ($node instanceof RawPhpNode) {
            $code .= $node->code;
        }

        if ($node instanceof ElementNode || $node instanceof FragmentNode) {
            foreach ($node->children as $child) {
                $code .= $this->collectPhpCodeRecursive($child);
            }
        }

        return $code;
    }

    /**
     * Assert that a document's PHP code contains expected string.
     *
     * @param \Sugar\Core\Ast\DocumentNode $document The document to check
     * @param string $expected The expected string to find
     */
    protected function assertPhpCodeContains(DocumentNode $document, string $expected): void
    {
        $code = $this->collectAllPhpCode($document);
        $this->assertStringContainsString($expected, $code, sprintf(
            "Expected PHP code to contain '%s'.\nActual code:\n%s",
            $expected,
            $code,
        ));
    }

    /**
     * Assert that a document contains a TextNode with given content (recursive).
     *
     * @param \Sugar\Core\Ast\DocumentNode $document The document to check
     * @param string $text The text to find
     */
    protected function assertDocumentContainsText(DocumentNode $document, string $text): void
    {
        $found = $this->findTextInNode($document, $text);
        $this->assertTrue($found, sprintf("Expected document to contain text '%s'", $text));
    }

    /**
     * Recursively search for text content in nodes.
     *
     * @param \Sugar\Core\Ast\Node $node The node to search
     * @param string $text The text to find
     * @return bool True if found
     */
    private function findTextInNode(Node $node, string $text): bool
    {
        if ($node instanceof TextNode && str_contains($node->content, $text)) {
            return true;
        }

        if ($node instanceof RawPhpNode && str_contains($node->code, $text)) {
            return true;
        }

        if ($node instanceof DocumentNode) {
            foreach ($node->children as $child) {
                if ($this->findTextInNode($child, $text)) {
                    return true;
                }
            }
        }

        if ($node instanceof ElementNode || $node instanceof FragmentNode) {
            foreach ($node->children as $child) {
                if ($this->findTextInNode($child, $text)) {
                    return true;
                }
            }
        }

        return false;
    }
}
