<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\CachedTemplate;
use Sugar\Core\Cache\CacheKey;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Runtime\BlockManager;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Core\Runtime\TemplateRenderer;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;
use Sugar\Extension\Component\Runtime\ComponentRenderer;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Tests for the extension-owned component renderer.
 *
 * ComponentRenderer delegates compilation and execution to TemplateRenderer.
 * These tests verify component-specific behavior: name resolution, slot
 * normalization, attribute merging, variant cache keys, and dependency tracking.
 */
final class ComponentRendererTest extends TestCase
{
    use CompilerTestTrait;
    use TempDirectoryTrait;
    use TemplateTestHelperTrait;

    private FileCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new FileCache(
            cacheDir: $this->createTempDir('sugar_renderer_test_'),
        );

        $this->setUpCompilerWithStringLoader(
            templates: [
                'components/s-alert.sugar.php' => $this->loadTemplate('components/s-alert.sugar.php'),
            ],
            config: new SugarConfig(),
        );
    }

    protected function tearDown(): void
    {
        RuntimeEnvironment::clearService(TemplateRenderer::class);
        $this->cleanupTempDirs();
        parent::tearDown();
    }

    public function testRenderComponentThrowsForEmptyName(): void
    {
        $renderer = $this->createRenderer();

        $this->expectException(ComponentNotFoundException::class);

        $renderer->renderComponent('');
    }

    public function testRenderComponentMergesSlotsAndAttributes(): void
    {
        $renderer = $this->createRenderer();

        $output = $renderer->renderComponent(
            name: 'alert',
            vars: ['title' => 'Warning'],
            slots: ['slot' => 'Save'],
            attributes: ['class' => 'large', 'data-extra' => 'yes'],
        );

        $this->assertStringContainsString('<div', $output);
        $this->assertStringContainsString('class="alert alert-info large"', $output);
        $this->assertStringContainsString('data-extra="yes"', $output);
        $this->assertStringContainsString('Save', $output);
        $this->assertStringContainsString('Warning', $output);
    }

    public function testRenderComponentCachesVariant(): void
    {
        $renderer = $this->createRenderer();

        $output = $renderer->renderComponent(
            name: 'alert',
            slots: ['slot' => 'Save', 'footer' => 'Footer'],
        );

        $this->assertStringContainsString('Save', $output);

        $componentPath = $this->componentLoader->getComponentPath('alert');
        $cacheKey = CacheKey::fromTemplate($componentPath, ['footer', 'slot']);

        $cached = $this->cache->get($cacheKey, debug: true);

        $this->assertInstanceOf(CachedTemplate::class, $cached);
    }

    public function testRenderComponentNormalizesSlotValues(): void
    {
        if (!$this->templateLoader instanceof StringTemplateLoader) {
            $this->markTestSkipped('Test requires StringTemplateLoader');
        }

        $this->templateLoader->addTemplate(
            'components/s-slot-test.sugar.php',
            '<div class="slot-test">'
            . '<div class="header"><?= $header ?></div>'
            . '<div class="body"><?= $slot ?></div>'
            . '<div class="footer"><?= $footer ?></div>'
            . '</div>',
        );

        $renderer = $this->createRenderer();

        $output = $renderer->renderComponent(
            name: 'slot-test',
            slots: [
                'slot' => null,
                'header' => 123,
                'footer' => new class {
                    public function __toString(): string
                    {
                        return 'Footer';
                    }
                },
            ],
        );

        $this->assertStringContainsString('<div class="header">123</div>', $output);
        $this->assertStringContainsString('<div class="body"></div>', $output);
        $this->assertStringContainsString('<div class="footer">Footer</div>', $output);
    }

    public function testRenderComponentNormalizesAttributes(): void
    {
        if (!$this->templateLoader instanceof StringTemplateLoader) {
            $this->markTestSkipped('Test requires StringTemplateLoader');
        }

        $this->templateLoader->addTemplate(
            'components/s-attr-test.sugar.php',
            '<div class="box" s:spread="$__sugar_attrs"><?= $slot ?></div>',
        );

        $renderer = $this->createRenderer();

        $output = $renderer->renderComponent(
            name: 'attr-test',
            slots: ['slot' => 'Content'],
            attributes: [
                'data-ok' => 'yes',
                'data-null' => null,
                'data-arr' => ['skip'],
                'data-obj' => new class {
                    public function __toString(): string
                    {
                        return 'obj';
                    }
                },
            ],
        );

        $this->assertStringContainsString('data-ok="yes"', $output);
        $this->assertStringContainsString('data-obj="obj"', $output);
        $this->assertStringNotContainsString('data-null', $output);
        $this->assertStringNotContainsString('data-arr', $output);
    }

    public function testRenderComponentBindsTemplateContext(): void
    {
        if (!$this->templateLoader instanceof StringTemplateLoader) {
            $this->markTestSkipped('Test requires StringTemplateLoader');
        }

        $this->templateLoader->addTemplate(
            'components/s-context-test.sugar.php',
            '<div><?= $this->greet() ?></div>',
        );

        $context = new class {
            public function greet(): string
            {
                return 'Hello';
            }
        };

        $renderer = $this->createRenderer(context: $context);

        $output = $renderer->renderComponent(name: 'context-test');

        $this->assertStringContainsString('<div>Hello</div>', $output);
    }

    public function testRenderComponentUpdatesProvidedTracker(): void
    {
        $tracker = new DependencyTracker();
        $renderer = $this->createRenderer(tracker: $tracker);

        $renderer->renderComponent(name: 'alert');

        $componentPath = $this->componentLoader->getComponentFilePath('alert');
        $metadata = $tracker->getMetadata($componentPath);

        $this->assertContains($componentPath, $metadata->components);
    }

    public function testRenderComponentAlwaysIncludesDefaultSlot(): void
    {
        if (!$this->templateLoader instanceof StringTemplateLoader) {
            $this->markTestSkipped('Test requires StringTemplateLoader');
        }

        $this->templateLoader->addTemplate(
            'components/s-simple.sugar.php',
            '<div><?= $slot ?></div>',
        );

        $renderer = $this->createRenderer();

        // No slots passed - default slot should still be empty string
        $output = $renderer->renderComponent(name: 'simple');

        $this->assertStringContainsString('<div></div>', $output);
    }

    public function testRenderComponentSortsSlotNamesForVariantKey(): void
    {
        if (!$this->templateLoader instanceof StringTemplateLoader) {
            $this->markTestSkipped('Test requires StringTemplateLoader');
        }

        $this->templateLoader->addTemplate(
            'components/s-multi-slot.sugar.php',
            '<div><?= $slot ?> <?= $header ?> <?= $footer ?></div>',
        );

        $renderer = $this->createRenderer();

        // Pass slots in non-alphabetical order
        $output1 = $renderer->renderComponent(
            name: 'multi-slot',
            slots: ['footer' => 'F', 'header' => 'H', 'slot' => 'S'],
        );

        $output2 = $renderer->renderComponent(
            name: 'multi-slot',
            slots: ['header' => 'H', 'slot' => 'S', 'footer' => 'F'],
        );

        // Both should produce same output since variant keys are sorted
        $this->assertSame($output1, $output2);
    }

    /**
     * Create a ComponentRenderer with a TemplateRenderer registered in RuntimeEnvironment.
     *
     * @param \Sugar\Core\Cache\DependencyTracker|null $tracker Optional dependency tracker
     * @param object|null $context Optional template context for $this binding
     */
    private function createRenderer(
        ?DependencyTracker $tracker = null,
        ?object $context = null,
    ): ComponentRenderer {
        $templateRenderer = new TemplateRenderer(
            compiler: $this->compiler,
            loader: $this->templateLoader,
            cache: $this->cache,
            blockManager: new BlockManager(),
            tracker: $tracker,
            debug: true,
            templateContext: $context,
        );

        RuntimeEnvironment::setService(
            TemplateRenderer::class,
            $templateRenderer,
        );

        return new ComponentRenderer(
            loader: $this->componentLoader,
        );
    }
}
