<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Cache\CachedTemplate;
use Sugar\Cache\DependencyTracker;
use Sugar\Cache\FileCache;
use Sugar\Config\SugarConfig;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Loader\StringTemplateLoader;
use Sugar\Runtime\ComponentRenderer;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class ComponentRendererTest extends TestCase
{
    use CompilerTestTrait;
    use TempDirectoryTrait;
    use TemplateTestHelperTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompilerWithStringLoader(
            templates: [],
            components: [
                'alert' => $this->loadTemplate('components/s-alert.sugar.php'),
            ],
            config: new SugarConfig(),
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

        $componentPath = $this->templateLoader->getComponentPath('alert');
        $cacheKey = $componentPath . '::slots:footer|slot';

        $cached = $cache->get($cacheKey);

        $this->assertInstanceOf(CachedTemplate::class, $cached);
    }

    public function testRenderComponentNormalizesSlotValues(): void
    {
        $this->assertInstanceOf(StringTemplateLoader::class, $this->templateLoader);
        $this->templateLoader->addComponent(
            'slot-test',
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
        $this->assertInstanceOf(StringTemplateLoader::class, $this->templateLoader);
        $this->templateLoader->addComponent(
            'attr-test',
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
        $this->assertInstanceOf(StringTemplateLoader::class, $this->templateLoader);
        $this->templateLoader->addComponent(
            'context-test',
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

        $componentPath = $this->templateLoader->getComponentFilePath('alert');
        $metadata = $tracker->getMetadata($componentPath);

        $this->assertContains($componentPath, $metadata->components);
    }

    private function createRenderer(
        ?object $context = null,
        ?FileCache $cache = null,
        ?DependencyTracker $tracker = null,
    ): ComponentRenderer {
        $cache = $cache ?? $this->createCache();

        return new ComponentRenderer(
            compiler: $this->compiler,
            loader: $this->templateLoader,
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
