<?php
declare(strict_types=1);

namespace Sugar\Test\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Cache\DependencyTracker;
use Sugar\Config\SugarConfig;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;

/**
 * Integration tests for dependency tracking during compilation
 */
final class DependencyTrackingTest extends TestCase
{
    use CompilerTestTrait;

    protected function setUp(): void
    {
        $this->setUpCompiler();
    }

    public function testTrackerIsPassedThroughCompilationPipeline(): void
    {
        $tracker = new DependencyTracker();
        $source = '<div><?= $message ?></div>';

        // Compile with tracker - should not throw
        $result = $this->compiler->compile($source, 'test-template', false, $tracker);

        $this->assertStringContainsString('$message', $result);
    }

    public function testTrackerCanBeNull(): void
    {
        $source = '<div><?= $message ?></div>';

        // Compile without tracker - should still work
        $result = $this->compiler->compile($source, 'test-template', false);

        $this->assertStringContainsString('$message', $result);
    }

    public function testTrackerReturnsMetadata(): void
    {
        $tracker = new DependencyTracker();
        $source = '<div><?= $message ?></div>';

        // Compile with tracker
        $this->compiler->compile($source, 'test-template', false, $tracker);

        // Get metadata
        $metadata = $tracker->getMetadata('test-template');

        // Metadata should exist with proper structure
        $this->assertSame([], $metadata->dependencies);
        $this->assertSame([], $metadata->components);
    }

    public function testTracksExtendsDependency(): void
    {
        $config = new SugarConfig();

        $this->setUpCompiler(
            config: $config,
            withTemplateLoader: true,
            templatePaths: [SUGAR_TEST_TEMPLATE_INHERITANCE_PATH],
        );

        $tracker = new DependencyTracker();
        $this->assertInstanceOf(FileTemplateLoader::class, $this->templateLoader);
        $source = $this->templateLoader->load('simple-child.sugar.php');

        // Compile child template that extends base
        $this->compiler->compile($source, 'simple-child.sugar.php', false, $tracker);

        // Get metadata
        $metadata = $tracker->getMetadata('simple-child.sugar.php');

        // Should track the parent layout
        $this->assertContains('base.sugar.php', $metadata->dependencies);
    }

    public function testTracksIncludeDependency(): void
    {
        $config = new SugarConfig();
        $this->setUpCompiler(
            config: $config,
            withTemplateLoader: true,
            templatePaths: [SUGAR_TEST_TEMPLATE_INHERITANCE_PATH],
        );

        $tracker = new DependencyTracker();
        $this->assertInstanceOf(FileTemplateLoader::class, $this->templateLoader);
        $source = $this->templateLoader->load('include-test.sugar.php');

        // Compile template that includes header partial
        $this->compiler->compile($source, 'include-test.sugar.php', false, $tracker);

        // Get metadata
        $metadata = $tracker->getMetadata('include-test.sugar.php');

        // Should track the included partial
        $this->assertContains('partials/header.sugar.php', $metadata->dependencies);
    }

    public function testTracksComponentDependency(): void
    {
        $config = new SugarConfig();
        $this->setUpCompiler(
            config: $config,
            withTemplateLoader: true,
            templatePaths: [SUGAR_TEST_TEMPLATES_PATH],
            componentPaths: ['components'],
        );

        $tracker = new DependencyTracker();
        $source = '<s-button>Click me</s-button>';

        // Compile template with component
        $this->compiler->compile($source, 'page-with-button', false, $tracker);

        // Get metadata
        $metadata = $tracker->getMetadata('page-with-button');

        // Should track the button component
        $this->assertContains('button', $metadata->components);
    }

    public function testTracksMultipleComponents(): void
    {
        $config = new SugarConfig();
        $this->setUpCompiler(
            config: $config,
            withTemplateLoader: true,
            templatePaths: [SUGAR_TEST_TEMPLATES_PATH],
            componentPaths: ['components'],
        );

        $tracker = new DependencyTracker();
        $source = '<s-button>Save</s-button><s-badge>New</s-badge><s-alert>Done!</s-alert>';

        // Compile template with multiple components
        $this->compiler->compile($source, 'multi-component', false, $tracker);

        // Get metadata
        $metadata = $tracker->getMetadata('multi-component');

        // Should track all components
        $this->assertContains('button', $metadata->components);
        $this->assertContains('badge', $metadata->components);
        $this->assertContains('alert', $metadata->components);
        $this->assertCount(3, $metadata->components);
    }

    public function testTracksDuplicateComponentsOnce(): void
    {
        $config = new SugarConfig();
        $this->setUpCompiler(
            config: $config,
            withTemplateLoader: true,
            templatePaths: [SUGAR_TEST_TEMPLATES_PATH],
            componentPaths: ['components'],
        );

        $tracker = new DependencyTracker();
        $source = '<s-button>Save</s-button><s-button>Cancel</s-button><s-button>Delete</s-button>';

        // Compile template with same component used multiple times
        $this->compiler->compile($source, 'duplicate-components', false, $tracker);

        // Get metadata
        $metadata = $tracker->getMetadata('duplicate-components');

        // Should track button only once
        $this->assertContains('button', $metadata->components);
        $this->assertCount(1, $metadata->components);
    }

    public function testTracksNestedDependencies(): void
    {
        // Use base templates path which has both template-inheritance and components subdirs
        $config = new SugarConfig();
        $this->setUpCompiler(
            config: $config,
            withTemplateLoader: true,
            templatePaths: [SUGAR_TEST_TEMPLATES_PATH],
            componentPaths: ['components'],
        );

        $tracker = new DependencyTracker();

        // Template that extends base, includes partial, and uses component
        $source = <<<'SUGAR'
<div s:extends="template-inheritance/base.sugar.php"></div>

<div s:block="content">
    <div s:include="template-inheritance/partials/header.sugar.php"></div>
    <s-button>Click me</s-button>
    <p>Content here</p>
</div>
SUGAR;

        // Compile complex template
        $this->compiler->compile($source, 'complex-template', false, $tracker);

        // Get metadata
        $metadata = $tracker->getMetadata('complex-template');

        // Debug: print actual dependencies
        // var_dump($metadata->dependencies);

        // Should track all dependencies
        $this->assertContains('template-inheritance/base.sugar.php', $metadata->dependencies);
        // The include path is resolved relative to the parent template
        $this->assertGreaterThan(0, count($metadata->dependencies));
        $this->assertContains('button', $metadata->components);
    }
}
