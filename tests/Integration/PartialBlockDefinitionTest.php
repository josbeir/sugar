<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Tests\Helper\Trait\EngineTestTrait;

/**
 * Integration tests verifying that block definitions inside included partials
 * propagate correctly to the parent template's inheritance system.
 *
 * When a partial contains `s:block`, it must be usable in two ways:
 * 1. **Defining context**: Included at the top level of a child extends-template
 *    (outside any `s:block`). In this context the partial should call
 *    `defineBlock()` so the parent layout uses the partial's content.
 * 2. **Rendering context**: Included inside a layout template or inside an
 *    already-rendered block. In this context it should call `renderBlock()`
 *    as a normal layout placeholder.
 */
final class PartialBlockDefinitionTest extends TestCase
{
    use EngineTestTrait;

    /**
     * A partial with `s:block` included at the top level of a child extends-template
     * should provide the block content to the parent layout (element block in partial).
     */
    public function testElementBlockInPartialPropagatesToLayout(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'layout.sugar.php' => '<main s:block="toc">Default TOC</main>',
                'partial-toc.sugar.php' => '<div s:block="toc">TOC from partial</div>',
                'child.sugar.php' => implode('', [
                    '<s-template s:extends="layout.sugar.php" />',
                    '<s-template s:include="partial-toc.sugar.php" />',
                ]),
            ],
        );

        $result = $engine->render('child.sugar.php');

        $this->assertStringContainsString('TOC from partial', $result);
        $this->assertStringNotContainsString('Default TOC', $result);
        // Layout's main wrapper is still rendered
        $this->assertStringContainsString('<main>', $result);
    }

    /**
     * A partial with a fragment `s:block` included at the top level of a child
     * extends-template should propagate the block to the parent layout.
     */
    public function testFragmentBlockInPartialPropagatesToLayout(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'layout.sugar.php' => '<s-template s:block="toc">Default TOC</s-template>',
                'partial-toc.sugar.php' => '<s-template s:block="toc">Fragment TOC from partial</s-template>',
                'child.sugar.php' => implode('', [
                    '<s-template s:extends="layout.sugar.php" />',
                    '<s-template s:include="partial-toc.sugar.php" />',
                ]),
            ],
        );

        $result = $engine->render('child.sugar.php');

        $this->assertStringContainsString('Fragment TOC from partial', $result);
        $this->assertStringNotContainsString('Default TOC', $result);
    }

    /**
     * When a partial with `s:block` is included inside a layout template (rendering
     * context), it behaves as a normal layout block — renders with override or default.
     */
    public function testPartialBlockActsAsLayoutBlockInRenderingContext(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                // Layout includes the partial as a nested layout block
                'layout.sugar.php' => implode('', [
                    '<div>',
                    '<s-template s:include="partial-toc.sugar.php" />',
                    '</div>',
                ]),
                'partial-toc.sugar.php' => '<section s:block="toc">Default TOC section</section>',
                // Child does not define the toc block
                'child-no-toc.sugar.php' => '<s-template s:extends="layout.sugar.php" />',
                // Child defines the toc block
                'child-with-toc.sugar.php' => implode('', [
                    '<s-template s:extends="layout.sugar.php" />',
                    '<s-template s:block="toc">Custom TOC</s-template>',
                ]),
            ],
        );

        $noTocResult = $engine->render('child-no-toc.sugar.php');
        $this->assertStringContainsString('Default TOC section', $noTocResult);
        $this->assertStringContainsString('<section>', $noTocResult);

        $withTocResult = $engine->render('child-with-toc.sugar.php');
        $this->assertStringContainsString('Custom TOC', $withTocResult);
        $this->assertStringNotContainsString('Default TOC section', $withTocResult);
    }

    /**
     * A conditional block in a partial (s:notempty + s:block) should only define
     * the block when the condition is true; otherwise the layout default is used.
     */
    public function testConditionalBlockInPartialRespectsCondition(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'layout.sugar.php' => '<nav s:block="toc">Default TOC</nav>',
                'partial-toc.sugar.php' => '<div s:notempty="$items" s:block="toc">Conditional TOC</div>',
                'child.sugar.php' => implode('', [
                    '<s-template s:extends="layout.sugar.php" />',
                    '<s-template s:include="partial-toc.sugar.php" />',
                ]),
            ],
        );

        // When $items is non-empty, partial block is registered and used
        $result = $engine->render('child.sugar.php', ['items' => ['a', 'b']]);
        $this->assertStringContainsString('Conditional TOC', $result);
        $this->assertStringNotContainsString('Default TOC', $result);

        // When $items is empty, partial block is NOT registered and layout default is used
        $result = $engine->render('child.sugar.php', ['items' => []]);
        $this->assertStringNotContainsString('Conditional TOC', $result);
        $this->assertStringContainsString('Default TOC', $result);
    }

    /**
     * Multiple partials can each define separate blocks in the same child template.
     */
    public function testMultiplePartialBlocksInSingleChildTemplate(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'layout.sugar.php' => implode('', [
                    '<header s:block="header">Default Header</header>',
                    '<main s:block="content">Default Content</main>',
                ]),
                'partial-header.sugar.php' => '<s-template s:block="header">Header from partial</s-template>',
                'partial-content.sugar.php' => '<s-template s:block="content">Content from partial</s-template>',
                'child.sugar.php' => implode('', [
                    '<s-template s:extends="layout.sugar.php" />',
                    '<s-template s:include="partial-header.sugar.php" />',
                    '<s-template s:include="partial-content.sugar.php" />',
                ]),
            ],
        );

        $result = $engine->render('child.sugar.php');

        $this->assertStringContainsString('Header from partial', $result);
        $this->assertStringContainsString('Content from partial', $result);
        $this->assertStringNotContainsString('Default Header', $result);
        $this->assertStringNotContainsString('Default Content', $result);
    }

    /**
     * Block registration depth is restored via try-finally even if an included partial throws.
     *
     * After catching the exception a subsequent render must complete successfully,
     * proving that the BlockManager is not left in a corrupted (depth > 0) state.
     */
    public function testBlockRegistrationDepthIsRestoredAfterPartialException(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'layout.sugar.php' => '<main s:block="content">Default</main>',
                'throwing-partial.sugar.php' => '<?php throw new \RuntimeException("partial failure"); ?>',
                'child-throws.sugar.php' => implode('', [
                    '<s-template s:extends="layout.sugar.php" />',
                    '<s-template s:include="throwing-partial.sugar.php" />',
                ]),
                'child-ok.sugar.php' => implode('', [
                    '<s-template s:extends="layout.sugar.php" />',
                    '<s-template s:block="content">OK content</s-template>',
                ]),
            ],
        );

        try {
            $engine->render('child-throws.sugar.php');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // Expected — partial failed during block registration.
        }

        // Block registration depth must be restored; subsequent render must succeed.
        $result = $engine->render('child-ok.sugar.php');
        $this->assertStringContainsString('OK content', $result);
    }

    /**
     * Explicit s:block in child template takes precedence over partial block.
     * Pre-extends includes are processed first, so the partial registers its block.
     * The explicit child s:block is then defined afterwards, overwriting the partial's
     * registration since defineBlock() does not deduplicate — last write wins.
     */
    public function testExplicitChildBlockTakesPrecedenceOverPartialBlock(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'layout.sugar.php' => '<main s:block="toc">Default TOC</main>',
                'partial-toc.sugar.php' => '<div s:block="toc">TOC from partial</div>',
                'child.sugar.php' => implode('', [
                    '<s-template s:extends="layout.sugar.php" />',
                    '<s-template s:block="toc">Explicit TOC</s-template>',
                    '<s-template s:include="partial-toc.sugar.php" />',
                ]),
            ],
        );

        $result = $engine->render('child.sugar.php');

        $this->assertStringContainsString('Explicit TOC', $result);
        $this->assertStringNotContainsString('TOC from partial', $result);
        $this->assertStringNotContainsString('Default TOC', $result);
    }
}
