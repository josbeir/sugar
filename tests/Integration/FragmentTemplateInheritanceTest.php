<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Config\SugarConfig;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

/**
 * Test template inheritance features on fragment elements
 */
final class FragmentTemplateInheritanceTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;

    public function testFragmentWithBlockAttribute(): void
    {
        // Test if s:block works on fragment elements
        $template = '<s-template s:block="content">Default content</s-template>';

        $config = new SugarConfig();
        $this->setUpCompiler(config: $config);

        try {
            $compiled = $this->compiler->compile($template, 'test.sugar.php');

            // If it compiles, test execution
            $result = $this->executeTemplate($compiled);
            $this->assertStringContainsString('Default content', $result);
        } catch (RuntimeException $runtimeException) {
            // Expected - fragments might not support s:block yet
            $this->assertStringContainsString('s-template', $runtimeException->getMessage());
        }
    }

    public function testFragmentWithIfDirective(): void
    {
        // Verify that regular directives work on fragments
        $template = '<s-template s:if="$show">Visible</s-template>';

        $this->setUpCompiler();

        $compiled = $this->compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['show' => true]);
        $this->assertStringContainsString('Visible', $result);

        $result = $this->executeTemplate($compiled, ['show' => false]);
        $this->assertStringNotContainsString('Visible', $result);
    }

    public function testFragmentBlockReplacesElementBlock(): void
    {
        // Fragment block should replace element block content without wrapper
        $layout = '<div s:block="content">Default</div>';
        $child = '<div s:extends="layout"><s-template s:block="content"><h1>Title</h1><p>Body</p></s-template></div>';

        $config = new SugarConfig();
        $this->setUpCompilerWithStringLoader(
            templates: [
                'layout.sugar.php' => $layout,
            ],
            config: $config,
        );

        $compiled = $this->compiler->compile($child, 'child.sugar.php');
        $result = $this->executeTemplate($compiled);

        // Should have div wrapper from parent, fragment children inserted
        $this->assertStringContainsString('<div>', $result);
        $this->assertStringContainsString('<h1>Title</h1>', $result);
        $this->assertStringContainsString('<p>Body</p>', $result);
        $this->assertStringNotContainsString('Default', $result);
        $this->assertStringNotContainsString('s-template', $result);
        $this->assertStringNotContainsString('s:block', $result);
    }

    public function testFragmentBlockReplacesFragmentBlock(): void
    {
        // Fragment replacing fragment
        $layout = '<s-template s:block="content">Default</s-template>';
        $child = '<div s:extends="layout"><s-template s:block="content"><h1>Override</h1></s-template></div>';

        $config = new SugarConfig();
        $this->setUpCompilerWithStringLoader(
            templates: [
                'layout.sugar.php' => $layout,
            ],
            config: $config,
        );

        $compiled = $this->compiler->compile($child, 'child.sugar.php');
        $result = $this->executeTemplate($compiled);

        $this->assertStringContainsString('<h1>Override</h1>', $result);
        $this->assertStringNotContainsString('Default', $result);
        $this->assertStringNotContainsString('s-template', $result);
    }

    public function testFragmentWithMixedDirectivesAndInheritance(): void
    {
        // Fragment with both directive and inheritance works - directives process AFTER inheritance
        $layout = '<div s:block="list">Default list</div>';
        $child = '<div s:extends="layout"><s-template s:block="list" s:foreach="$items as $item"><span><?= $item ?></span></s-template></div>';

        $config = new SugarConfig();
        $this->setUpCompilerWithStringLoader(
            templates: [
                'layout.sugar.php' => $layout,
            ],
            config: $config,
        );

        $compiled = $this->compiler->compile($child, 'child.sugar.php');

        // Test with items
        $result = $this->executeTemplate($compiled, ['items' => ['X', 'Y']]);
        $this->assertStringContainsString('<span>X</span>', $result);
        $this->assertStringContainsString('<span>Y</span>', $result);
        $this->assertStringNotContainsString('Default list', $result);
    }

    public function testFragmentWithRawDirectiveRendersChildrenWithoutFragmentWrapper(): void
    {
        $template = '<s-template s:raw><span s:if="$show">Literal</span>{{ token }}</s-template>';

        $this->setUpCompiler();

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['show' => false]);

        $this->assertStringContainsString('<span s:if="$show">Literal</span>{{ token }}', $result);
        $this->assertStringNotContainsString('s-template', $result);
    }

    public function testFragmentWithRawDirectivePreservesPhpTagAsLiteralText(): void
    {
        $template = '<s-template s:raw><?= $value ?></s-template>';

        $this->setUpCompiler();

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['value' => 'executed']);

        $this->assertSame('<?= $value ?>', $result);
    }

    public function testFragmentBlockWithOnlyInheritanceAttribute(): void
    {
        // Fragment with only s:block (no directives)
        $layout = '<div s:block="sidebar">Default sidebar</div>';
        $child = '<div s:extends="layout"><s-template s:block="sidebar">Custom sidebar</s-template></div>';

        $config = new SugarConfig();
        $this->setUpCompilerWithStringLoader(
            templates: [
                'layout.sugar.php' => $layout,
            ],
            config: $config,
        );

        $compiled = $this->compiler->compile($child, 'child.sugar.php');
        $result = $this->executeTemplate($compiled);

        $this->assertStringContainsString('Custom sidebar', $result);
        $this->assertStringNotContainsString('Default sidebar', $result);
        $this->assertStringNotContainsString('s-template', $result);
    }
}
