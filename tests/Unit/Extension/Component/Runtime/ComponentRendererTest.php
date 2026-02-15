<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\CachedTemplate;
use Sugar\Core\Cache\CacheMetadata;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\CompilationException;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Extension\Component\Compiler\ComponentTemplateCompiler;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;
use Sugar\Extension\Component\Runtime\ComponentRenderer;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Tests for the extension-owned component renderer.
 */
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

    public function testRenderComponentReturnsScalarFromCompiledClosure(): void
    {
        $renderer = $this->createRendererWithCachedTemplate(
            <<<'PHP'
<?php
return function (array $data): int {
    return 123;
};
PHP,
        );

        $output = $renderer->renderComponent(name: 'alert');

        $this->assertSame('123', $output);
    }

    public function testRenderComponentReturnsStringFromStringableObject(): void
    {
        $renderer = $this->createRendererWithCachedTemplate(
            <<<'PHP'
<?php
return function (array $data) {
    return new class {
        public function __toString(): string
        {
            return 'stringable';
        }
    };
};
PHP,
        );

        $output = $renderer->renderComponent(name: 'alert');

        $this->assertSame('stringable', $output);
    }

    public function testRenderComponentReturnsEmptyStringForNonStringableResult(): void
    {
        $renderer = $this->createRendererWithCachedTemplate(
            <<<'PHP'
<?php
return function (array $data): array {
    return ['nope'];
};
PHP,
        );

        $output = $renderer->renderComponent(name: 'alert');

        $this->assertSame('', $output);
    }

    public function testRenderComponentReturnsEmptyStringWhenCompiledFileIsNotClosure(): void
    {
        $renderer = $this->createRendererWithCachedTemplate(
            <<<'PHP'
<?php
return 'not-a-closure';
PHP,
        );

        $output = $renderer->renderComponent(name: 'alert');

        $this->assertSame('', $output);
    }

    public function testRenderComponentWrapsParseErrorAsCompilationException(): void
    {
        $renderer = $this->createRendererWithCachedTemplate(
            <<<'PHP'
<?php
return function (array $data): string {
    if (
};
PHP,
        );

        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('Compiled component contains invalid PHP');

        $renderer->renderComponent(name: 'alert');
    }

    private function createRenderer(
        ?object $context = null,
        ?TemplateCacheInterface $cache = null,
        ?DependencyTracker $tracker = null,
    ): ComponentRenderer {
        $cache = $cache ?? $this->createCache();

        return new ComponentRenderer(
            componentCompiler: new ComponentTemplateCompiler(
                compiler: $this->compiler,
                loader: $this->templateLoader,
            ),
            loader: $this->templateLoader,
            cache: $cache,
            tracker: $tracker,
            templateContext: $context,
        );
    }

    private function createRendererWithCachedTemplate(string $compiledPhp): ComponentRenderer
    {
        $compiledPath = $this->writeCompiledTemplate($compiledPhp);
        $cacheKey = $this->templateLoader->getComponentPath('alert') . '::slots:slot';
        $cached = new CachedTemplate($compiledPath, new CacheMetadata());

        $cache = new class ($cacheKey, $cached) implements TemplateCacheInterface {
            public function __construct(
                private string $key,
                private CachedTemplate $cached,
            ) {
            }

            public function get(string $key, bool $debug = false): ?CachedTemplate
            {
                return $key === $this->key ? $this->cached : null;
            }

            public function put(string $key, string $compiled, CacheMetadata $metadata): string
            {
                return $this->cached->path;
            }

            public function invalidate(string $key): array
            {
                return [];
            }

            public function delete(string $key): bool
            {
                return false;
            }

            public function flush(): void
            {
            }
        };

        return new ComponentRenderer(
            componentCompiler: new ComponentTemplateCompiler(
                compiler: $this->compiler,
                loader: $this->templateLoader,
            ),
            loader: $this->templateLoader,
            cache: $cache,
        );
    }

    private function writeCompiledTemplate(string $compiledPhp): string
    {
        $cacheDir = $this->createTempDir('sugar_compiled_');
        $path = $cacheDir . '/compiled.php';
        file_put_contents($path, $compiledPhp);

        return $path;
    }

    private function createCache(): FileCache
    {
        $cacheDir = $this->createTempDir('sugar_cache_');

        return new FileCache($cacheDir);
    }
}
