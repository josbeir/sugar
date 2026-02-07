<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Cache\CachedTemplate;
use Sugar\Cache\DependencyTracker;
use Sugar\Cache\FileCache;
use Sugar\Config\SugarConfig;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Runtime\ComponentRenderer;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

final class ComponentRendererTest extends TestCase
{
    use CompilerTestTrait;
    use TempDirectoryTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompiler(
            config: new SugarConfig(),
            withTemplateLoader: true,
            templatePaths: [SUGAR_TEST_TEMPLATES_PATH],
            componentPaths: ['components'],
        );
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
        $cache = $this->createCache();
        $renderer = $this->createRenderer(cache: $cache);

        $output = $renderer->renderComponent(
            name: 'alert',
            slots: ['slot' => 'Save', 'footer' => 'Footer'],
        );

        $this->assertStringContainsString('Save', $output);

        $loader = $this->templateLoader;
        $this->assertInstanceOf(FileTemplateLoader::class, $loader);

        $componentPath = $loader->getComponentPath('alert');
        $cacheKey = $componentPath . '::slots:footer|slot';

        $cached = $cache->get($cacheKey);

        $this->assertInstanceOf(CachedTemplate::class, $cached);
    }

    public function testRenderComponentNormalizesSlotValues(): void
    {
        $tempDir = $this->createTempDir('sugar_components_');
        $componentsDir = $tempDir . '/components';
        mkdir($componentsDir, 0755, true);

        file_put_contents(
            $componentsDir . '/s-slot-test.sugar.php',
            '<div class="slot-test">'
            . '<div class="header"><?= $header ?></div>'
            . '<div class="body"><?= $slot ?></div>'
            . '<div class="footer"><?= $footer ?></div>'
            . '</div>',
        );

        $this->setUpCompiler(
            config: new SugarConfig(),
            withTemplateLoader: true,
            templatePaths: [$tempDir],
            componentPaths: ['components'],
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
        $tempDir = $this->createTempDir('sugar_components_');
        $componentsDir = $tempDir . '/components';
        mkdir($componentsDir, 0755, true);

        file_put_contents(
            $componentsDir . '/s-attr-test.sugar.php',
            '<div class="box" s:spread="$__sugar_attrs"><?= $slot ?></div>',
        );

        $this->setUpCompiler(
            config: new SugarConfig(),
            withTemplateLoader: true,
            templatePaths: [$tempDir],
            componentPaths: ['components'],
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
        $tempDir = $this->createTempDir('sugar_components_');
        $componentsDir = $tempDir . '/components';
        mkdir($componentsDir, 0755, true);

        file_put_contents(
            $componentsDir . '/s-context-test.sugar.php',
            '<div><?= $this->greet() ?></div>',
        );

        $this->setUpCompiler(
            config: new SugarConfig(),
            withTemplateLoader: true,
            templatePaths: [$tempDir],
            componentPaths: ['components'],
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

        $loader = $this->templateLoader;
        $this->assertInstanceOf(FileTemplateLoader::class, $loader);

        $componentPath = $loader->getComponentPath('alert');
        $metadata = $tracker->getMetadata($componentPath);

        $this->assertContains('alert', $metadata->components);
    }

    private function createRenderer(
        ?object $context = null,
        ?FileCache $cache = null,
        ?DependencyTracker $tracker = null,
    ): ComponentRenderer {
        $cache = $cache ?? $this->createCache();

        $loader = $this->templateLoader;
        $this->assertInstanceOf(FileTemplateLoader::class, $loader);

        return new ComponentRenderer(
            compiler: $this->compiler,
            loader: $loader,
            cache: $cache,
            tracker: $tracker,
            templateContext: $context,
        );
    }

    private function createCache(): FileCache
    {
        $cacheDir = $this->createTempDir('sugar_cache_');

        return new FileCache($cacheDir);
    }
}
