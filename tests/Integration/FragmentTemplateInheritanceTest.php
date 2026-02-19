<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\EngineTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

/**
 * Test template inheritance features on fragment elements.
 *
 * Fragment elements (s-template) support inheritance attributes (s:block,
 * s:extends, etc.) rendering their children without a wrapper tag.
 */
final class FragmentTemplateInheritanceTest extends TestCase
{
    use CompilerTestTrait;
    use EngineTestTrait;
    use ExecuteTemplateTrait;

    /**
     * Test that s:block on a fragment renders default content via the runtime.
     */
    public function testFragmentWithBlockAttribute(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'test.sugar.php' => '<s-template s:block="content">Default content</s-template>',
            ],
        );

        $result = $engine->render('test.sugar.php');
        $this->assertStringContainsString('Default content', $result);
    }

    /**
     * Verify that regular directives work on fragments.
     */
    public function testFragmentWithIfDirective(): void
    {
        $template = '<s-template s:if="$show">Visible</s-template>';

        $this->setUpCompiler();

        $compiled = $this->compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['show' => true]);
        $this->assertStringContainsString('Visible', $result);

        $result = $this->executeTemplate($compiled, ['show' => false]);
        $this->assertStringNotContainsString('Visible', $result);
    }

    /**
     * Fragment block in child should replace element block in layout.
     */
    public function testFragmentBlockReplacesElementBlock(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'layout.sugar.php' => '<div s:block="content">Default</div>',
                'child.sugar.php' => '<div s:extends="layout.sugar.php"><s-template s:block="content"><h1>Title</h1><p>Body</p></s-template></div>',
            ],
        );

        $result = $engine->render('child.sugar.php');

        // Should have div wrapper from parent, fragment children inserted
        $this->assertStringContainsString('<div>', $result);
        $this->assertStringContainsString('<h1>Title</h1>', $result);
        $this->assertStringContainsString('<p>Body</p>', $result);
        $this->assertStringNotContainsString('Default', $result);
        $this->assertStringNotContainsString('s-template', $result);
        $this->assertStringNotContainsString('s:block', $result);
    }

    /**
     * Fragment replacing fragment should render override content.
     */
    public function testFragmentBlockReplacesFragmentBlock(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'layout.sugar.php' => '<s-template s:block="content">Default</s-template>',
                'child.sugar.php' => '<div s:extends="layout.sugar.php"><s-template s:block="content"><h1>Override</h1></s-template></div>',
            ],
        );

        $result = $engine->render('child.sugar.php');

        $this->assertStringContainsString('<h1>Override</h1>', $result);
        $this->assertStringNotContainsString('Default', $result);
        $this->assertStringNotContainsString('s-template', $result);
    }

    /**
     * Fragment with both directive and inheritance works — directives process after inheritance.
     */
    public function testFragmentWithMixedDirectivesAndInheritance(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'layout.sugar.php' => '<div s:block="list">Default list</div>',
                'child.sugar.php' => '<div s:extends="layout.sugar.php"><s-template s:block="list" s:foreach="$items as $item"><span><?= $item ?></span></s-template></div>',
            ],
        );

        $result = $engine->render('child.sugar.php', ['items' => ['X', 'Y']]);
        $this->assertStringContainsString('<span>X</span>', $result);
        $this->assertStringContainsString('<span>Y</span>', $result);
        $this->assertStringNotContainsString('Default list', $result);
    }

    /**
     * Fragment with s:raw should render children literally without wrapper.
     */
    public function testFragmentWithRawDirectiveRendersChildrenWithoutFragmentWrapper(): void
    {
        $template = '<s-template s:raw><span s:if="$show">Literal</span>{{ token }}</s-template>';

        $this->setUpCompiler();

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['show' => false]);

        $this->assertStringContainsString('<span s:if="$show">Literal</span>{{ token }}', $result);
        $this->assertStringNotContainsString('s-template', $result);
    }

    /**
     * Fragment with s:raw preserves PHP tags as literal text.
     */
    public function testFragmentWithRawDirectivePreservesPhpTagAsLiteralText(): void
    {
        $template = '<s-template s:raw><?= $value ?></s-template>';

        $this->setUpCompiler();

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['value' => 'executed']);

        $this->assertSame('<?= $value ?>', $result);
    }

    /**
     * Fragment with only s:block (no other directives) replaces parent block.
     */
    public function testFragmentBlockWithOnlyInheritanceAttribute(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'layout.sugar.php' => '<div s:block="sidebar">Default sidebar</div>',
                'child.sugar.php' => '<div s:extends="layout.sugar.php"><s-template s:block="sidebar">Custom sidebar</s-template></div>',
            ],
        );

        $result = $engine->render('child.sugar.php');

        $this->assertStringContainsString('Custom sidebar', $result);
        $this->assertStringNotContainsString('Default sidebar', $result);
        $this->assertStringNotContainsString('s-template', $result);
    }
}
